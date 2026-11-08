<?php
declare(strict_types=1);

/**
 * End-to-end API tests.
 *
 *   php tests/run.php
 *
 * The runner drops and recreates the disposable `lpg_test` database from
 * database/schema.sql, seeds one owner, starts the PHP built-in server with
 * tests/config.test.php, exercises the HTTP API, then stops the server.
 *
 * Requirements: PHP 8.0+ with curl/pdo_mysql, MariaDB/MySQL running, and the
 * database user in tests/config.test.php allowed to create the test database.
 *
 * Optional environment variables:
 *   TEST_BASE_URL  use an already-running server instead of starting one
 *   TEST_PORT      port for the auto-started server (default: first free 8095+)
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test runner must be run from the command line.\n");
    exit(1);
}

date_default_timezone_set('Asia/Manila');

$root = dirname(__DIR__);
$configFile = $root . '/tests/config.test.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "Missing tests/config.test.php\n");
    exit(1);
}
$config = require $configFile;
$db = $config['db'];
$testDb = (string) $db['name'];
if ($testDb === '' || strpos($testDb, 'test') === false) {
    fwrite(STDERR, "Refusing to run: tests/config.test.php must point at a database whose name contains 'test'.\n");
    exit(1);
}

/* ------------------------------------------------------------------------- */
/* Tiny assertion helpers                                                    */
/* ------------------------------------------------------------------------- */

$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = 0;
$GLOBALS['failures'] = [];

function check(bool $condition, string $label, string $detail = ''): void
{
    if ($condition) {
        $GLOBALS['pass']++;
        echo "  ok   {$label}\n";
        return;
    }
    $GLOBALS['fail']++;
    $message = $label . ($detail !== '' ? ' — ' . $detail : '');
    $GLOBALS['failures'][] = $message;
    echo "  FAIL {$message}\n";
}

function expect(array $response, int $status, string $label): array
{
    $body = substr((string) ($response['raw'] ?? ''), 0, 500);
    check(
        (int) $response['status'] === $status,
        $label,
        'expected HTTP ' . $status . ', got ' . (int) $response['status'] . ' body=' . $body
    );
    return $response;
}

function section(string $name): void
{
    echo "\n== {$name} ==\n";
}

/* ------------------------------------------------------------------------- */
/* HTTP client (cookie jar + CSRF)                                           */
/* ------------------------------------------------------------------------- */

final class ApiClient
{
    private string $base;
    private string $jar;
    public ?string $csrf = null;
    public ?array $user = null;

    public function __construct(string $base, string $name)
    {
        $this->base = rtrim($base, '/');
        $jar = tempnam(sys_get_temp_dir(), 'lpgjar_' . $name . '_');
        if ($jar === false) {
            throw new RuntimeException('Could not create a cookie jar.');
        }
        $this->jar = $jar;
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    public function absorb(array $response): void
    {
        if (is_array($response['json'] ?? null)) {
            $this->user = $response['json'];
            if (isset($response['json']['csrf_token']) && is_string($response['json']['csrf_token'])) {
                $this->csrf = $response['json']['csrf_token'];
            }
        }
    }

    public function login(string $username, string $password): array
    {
        $this->csrf = null;
        $this->ensureCsrf();
        $response = $this->request('POST', '/api/login', [
            'username' => $username,
            'password' => $password,
        ]);
        if ((int) $response['status'] === 200) {
            $this->absorb($response);
        }
        return $response;
    }

    private function ensureCsrf(): void
    {
        $response = $this->request('GET', '/api/csrf', null, [], false);
        if ((int) $response['status'] === 200 && isset($response['json']['csrf_token'])) {
            $this->csrf = (string) $response['json']['csrf_token'];
        }
    }

    public function request(
        string $method,
        string $path,
        ?array $body = null,
        array $headers = [],
        bool $csrf = true,
        ?string $rawBody = null,
        ?string $contentType = null
    ): array {
        $method = strtoupper($method);
        if ($csrf && !in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            if ($this->csrf === null || $this->csrf === '') {
                $this->ensureCsrf();
            }
        }

        $ch = curl_init($this->base . $path);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }

        $requestHeaders = ['Accept: application/json, text/csv'];
        if ($rawBody !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody);
            $requestHeaders[] = 'Content-Type: ' . ($contentType ?? 'application/json');
        } elseif ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $requestHeaders[] = 'Content-Type: ' . ($contentType ?? 'application/json');
        }
        if ($csrf && !in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) && $this->csrf !== null) {
            $requestHeaders[] = 'X-CSRF-Token: ' . $this->csrf;
        }
        foreach ($headers as $header) {
            $requestHeaders[] = $header;
        }

        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $colon = strpos($line, ':');
                if ($colon !== false) {
                    $key = strtolower(trim(substr($line, 0, $colon)));
                    $value = trim(substr($line, $colon + 1));
                    if ($key !== '') {
                        $responseHeaders[$key] = $value;
                    }
                }
                return $length;
            },
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return [
                'status' => 0,
                'body' => '',
                'json' => null,
                'headers' => $responseHeaders,
                'error' => $error,
            ];
        }
        $json = json_decode($raw, true);
        return [
            'status' => $status,
            'body' => $raw,
            'json' => is_array($json) ? $json : null,
            'headers' => $responseHeaders,
            'error' => '',
        ];
    }
}

/* ------------------------------------------------------------------------- */
/* Database reset + owner seed                                               */
/* ------------------------------------------------------------------------- */

function reset_database(string $root, array $db): PDO
{
    $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], (int) $db['port']);
    $pdo = new PDO($dsn, (string) $db['user'], (string) $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
    ]);
    $name = (string) $db['name'];
    $pdo->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '', $name) . '`');

    $schema = file_get_contents($root . '/database/schema.sql');
    if ($schema === false) {
        throw new RuntimeException('Could not read database/schema.sql');
    }
    $schema = preg_replace('/\blpg_db\b/', $name, $schema);
    if ($schema === null) {
        throw new RuntimeException('Could not prepare schema.sql for the test database');
    }
    $pdo->exec($schema);

    $pdo->exec('USE `' . str_replace('`', '', $name) . '`');
    return $pdo;
}

function seed_owner(PDO $pdo): void
{
    $st = $pdo->prepare(
        "INSERT INTO users (name, username, password_hash, role, must_change_password, is_active, session_version)
         VALUES ('Owner', 'admin', ?, 'owner', 1, 1, 1)"
    );
    $st->execute([password_hash('Bootstrap123!', PASSWORD_DEFAULT)]);
}

/* ------------------------------------------------------------------------- */
/* Built-in server lifecycle                                                 */
/* ------------------------------------------------------------------------- */

function free_port(): int
{
    for ($port = 8095; $port <= 8140; $port++) {
        $socket = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
        if ($socket !== false) {
            fclose($socket);
            return $port;
        }
    }
    throw new RuntimeException('No free TCP port found between 8095 and 8140.');
}

function ping(string $base): bool
{
    $ch = curl_init($base . '/api/health');
    if ($ch === false) {
        return false;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 1,
        CURLOPT_TIMEOUT => 2,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $body !== false && $status === 200;
}

function start_server(string $root, string $configFile): array
{
    $port = free_port();
    $base = 'http://127.0.0.1:' . $port;
    $log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lpg-test-server-' . $port . '-' . getmypid() . '.log';
    @unlink($log);

    $env = getenv();
    if (!is_array($env)) {
        $env = [];
    }
    $env['LPG_CONFIG_FILE'] = $configFile;

    $command = escapeshellarg(PHP_BINARY)
        . ' -S 127.0.0.1:' . $port
        . ' -t ' . escapeshellarg($root)
        . ' ' . escapeshellarg($root . '/tests/router.php');

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $log, 'a'],
        2 => ['file', $log, 'a'],
    ];
    $process = proc_open($command, $descriptors, $pipes, $root, $env);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the PHP built-in server.');
    }

    for ($i = 0; $i < 80; $i++) {
        if (ping($base)) {
            return ['process' => $process, 'base' => $base, 'log' => $log, 'port' => $port];
        }
        usleep(100000);
    }

    $output = is_file($log) ? (string) file_get_contents($log) : '';
    stop_server(['process' => $process]);
    throw new RuntimeException("The PHP test server did not start on port {$port}.\n" . $output);
}

function stop_server(array $server): void
{
    if (!isset($server['process']) || !is_resource($server['process'])) {
        return;
    }
    $status = proc_get_status($server['process']);
    $pid = (int) ($status['pid'] ?? 0);
    if ($pid > 0 && stripos(PHP_OS_FAMILY, 'Windows') !== false) {
        // proc_open on Windows runs the command through cmd.exe; terminate the
        // whole tree or the php -S child keeps the port and log file open.
        @exec('taskkill /F /T /PID ' . $pid . ' 2>NUL');
    } else {
        @proc_terminate($server['process']);
    }
    usleep(250000);
    @proc_close($server['process']);
}

/* ------------------------------------------------------------------------- */
/* Boot                                                                      */
/* ------------------------------------------------------------------------- */

echo "Resetting {$testDb} from database/schema.sql ...\n";
$pdo = reset_database($root, $db);
seed_owner($pdo);

$mainId = (int) $pdo->query("SELECT id FROM branches WHERE code='MAIN' LIMIT 1")->fetchColumn();
if ($mainId < 1) {
    fwrite(STDERR, "Main branch was not created by the schema.\n");
    exit(1);
}
$sizes = $pdo->query('SELECT id, label FROM cylinder_sizes ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
if (count($sizes) < 2) {
    fwrite(STDERR, "Expected at least two cylinder sizes in the schema.\n");
    exit(1);
}
$size1 = (int) $sizes[0]['id'];
$size2 = (int) $sizes[1]['id'];

$managedServer = null;
if (getenv('TEST_BASE_URL')) {
    $base = rtrim((string) getenv('TEST_BASE_URL'), '/');
    echo "Using existing server at {$base}\n";
} else {
    echo "Starting PHP built-in server ...\n";
    $managedServer = start_server($root, $configFile);
    $base = $managedServer['base'];
    echo "Server: {$base}\n";
}

$owner = new ApiClient($base, 'owner');
$anon = new ApiClient($base, 'anon');
$staff = new ApiClient($base, 'staff');
$customer = new ApiClient($base, 'customer');
$today = date('Y-m-d');

try {
    /* --------------------------------------------------------------------- */
    section('Health, CSRF and security headers');
    /* --------------------------------------------------------------------- */

    $r = $anon->request('GET', '/api/health', null, [], false);
    expect($r, 200, 'GET /api/health');
    check(($r['json']['ok'] ?? null) === true, 'health returns ok=true');
    check(isset($r['headers']['x-content-type-options']) && $r['headers']['x-content-type-options'] === 'nosniff', 'X-Content-Type-Options header is nosniff');
    check(isset($r['headers']['x-frame-options']) && $r['headers']['x-frame-options'] === 'DENY', 'X-Frame-Options header is DENY');
    check(isset($r['headers']['content-security-policy']) && strpos((string) $r['headers']['content-security-policy'], "default-src 'self'") !== false, 'CSP header is present');

    $r = $anon->request('GET', '/api/csrf', null, [], false);
    expect($r, 200, 'GET /api/csrf');
    $anonToken = (string) ($r['json']['csrf_token'] ?? '');
    check(strlen($anonToken) >= 32, 'CSRF token is a long random value');

    $r = $anon->request('POST', '/api/login', ['username' => 'admin', 'password' => 'x'], [], false);
    expect($r, 419, 'POST /api/login without CSRF is rejected');
    check(($r['json']['code'] ?? '') === 'csrf_failed', 'missing CSRF returns csrf_failed');

    $r = $anon->request('GET', '/api/dashboard', null, [], false);
    expect($r, 401, 'GET /api/dashboard without a session is rejected');
    check(($r['json']['code'] ?? '') === 'unauthorized', 'unauthenticated request returns unauthorized');

    $r = $anon->request('POST', '/api/login', null, [], true, '{', 'text/plain');
    expect($r, 415, 'POST with text/plain Content-Type is rejected');

    $r = $anon->request('POST', '/api/login', null, [], true, '{', 'application/json');
    expect($r, 400, 'POST with malformed JSON is rejected');
    check(($r['json']['code'] ?? '') === 'invalid_json', 'malformed JSON returns invalid_json');

    /* --------------------------------------------------------------------- */
    section('Forced first-login password change');
    /* --------------------------------------------------------------------- */

    $r = $owner->login('admin', 'WrongPassword123!');
    expect($r, 401, 'owner login with wrong password fails');
    check(($r['json']['code'] ?? '') === 'invalid_credentials', 'wrong password returns invalid_credentials');

    $r = $owner->login('admin', 'Bootstrap123!');
    expect($r, 200, 'owner login with bootstrap password');
    check((int) ($r['json']['must_change_password'] ?? -1) === 1, 'bootstrap login is forced to change password');
    check(($r['json']['role'] ?? '') === 'owner', 'bootstrap login has owner role');

    $r = $owner->request('GET', '/api/dashboard');
    expect($r, 403, 'protected endpoint is blocked until password changes');
    check(($r['json']['code'] ?? '') === 'password_change_required', 'blocked endpoint reports password_change_required');

    $r = $owner->request('POST', '/api/me/password', [
        'current_password' => 'Bootstrap123!',
        'new_password' => 'OwnerPass123!',
    ]);
    expect($r, 200, 'owner changes the bootstrap password');
    $owner->absorb($r);
    check((int) ($r['json']['must_change_password'] ?? -1) === 0, 'password change clears must_change_password');

    $r = $owner->request('GET', '/api/me');
    expect($r, 200, 'owner session works after password change');
    check(($r['json']['role'] ?? '') === 'owner', 'GET /api/me returns the owner role');

    /* --------------------------------------------------------------------- */
    section('Branches and write-branch selection');
    /* --------------------------------------------------------------------- */

    $r = $owner->request('GET', '/api/branches');
    expect($r, 200, 'owner lists branches');
    check(is_array($r['json']) && count($r['json']) >= 1, 'branch list is not empty');

    $r = $owner->request('POST', '/api/branches', [
        'name' => 'North Branch',
        'code' => 'north',
        'address' => 'North Road',
        'contact_phone' => '09170000000',
        'operating_hours' => '08:00-18:00',
        'max_order_qty' => 10,
    ]);
    expect($r, 201, 'owner creates a second branch');
    $northId = (int) ($r['json']['id'] ?? 0);
    check($northId > 0, 'new branch has an id');
    check(($r['json']['code'] ?? '') === 'NORTH', 'branch code is normalised to uppercase');

    $r = $owner->request('POST', '/api/branches', ['name' => 'Duplicate', 'code' => 'NORTH']);
    expect($r, 409, 'duplicate branch code is rejected');

    $r = $owner->request('GET', '/api/branches/public', null, [], false);
    expect($r, 200, 'public branch list is available');
    $publicIds = array_map(static fn($b) => (int) $b['id'], is_array($r['json']) ? $r['json'] : []);
    check(in_array($northId, $publicIds, true), 'public branch list includes the new branch');

    $r = $owner->request('POST', '/api/adjustments', [
        'size_id' => $size1,
        'reason' => 'Opening count',
        'full' => 1,
    ]);
    expect($r, 400, 'owner write without a branch is rejected');
    check(($r['json']['code'] ?? '') === 'branch_required', 'missing write branch returns branch_required');

    $r = $owner->request('POST', '/api/adjustments', [
        'size_id' => $size1,
        'reason' => 'Opening count',
        'full' => 1,
    ], ['X-Branch-Id: all']);
    expect($r, 400, 'owner write with branch "all" is rejected');
    check(($r['json']['code'] ?? '') === 'branch_required', 'branch "all" returns branch_required');

    /* --------------------------------------------------------------------- */
    section('Per-branch prices');
    /* --------------------------------------------------------------------- */

    $r = $owner->request('PUT', '/api/branches/' . $mainId . '/prices', [
        'prices' => [
            ['size_id' => $size1, 'price' => 100],
            ['size_id' => $size2, 'price' => 200],
        ],
    ]);
    expect($r, 200, 'owner sets main branch prices');

    $r = $owner->request('PUT', '/api/branches/' . $northId . '/prices', [
        'prices' => [
            ['size_id' => $size1, 'price' => 110],
            ['size_id' => $size2, 'price' => 210],
        ],
    ]);
    expect($r, 200, 'owner sets north branch prices');

    $r = $anon->request('GET', '/api/sizes?branch_id=' . $northId, null, [], false);
    expect($r, 200, 'public sizes honour a branch filter');
    $northSize1 = null;
    foreach (is_array($r['json']) ? $r['json'] : [] as $row) {
        if ((int) ($row['id'] ?? 0) === $size1) {
            $northSize1 = $row;
        }
    }
    check($northSize1 !== null, 'branch size list contains the first size');
    check(abs((float) ($northSize1['selling_price'] ?? -1) - 110.0) < 0.001, 'north size price is the branch override');

    /* --------------------------------------------------------------------- */
    section('Staff account, branch locking and permissions');
    /* --------------------------------------------------------------------- */

    $r = $owner->request('POST', '/api/users', [
        'name' => 'Main Staff',
        'username' => 'staffmain',
        'password' => 'StaffTemp123!',
        'role' => 'staff',
        'branch_id' => $mainId,
        'contact' => '09171111111',
    ]);
    expect($r, 201, 'owner creates a staff account in the main branch');
    $staffId = (int) ($r['json']['id'] ?? 0);

    $r = $staff->login('staffmain', 'StaffTemp123!');
    expect($r, 200, 'staff logs in with the temporary password');
    check((int) ($r['json']['must_change_password'] ?? -1) === 1, 'new staff account must change password');
    check((int) ($r['json']['branch_id'] ?? 0) === $mainId, 'staff account is bound to the main branch');

    $r = $staff->request('GET', '/api/dashboard');
    expect($r, 403, 'staff cannot use the app before changing password');

    $r = $staff->request('POST', '/api/me/password', [
        'current_password' => 'StaffTemp123!',
        'new_password' => 'StaffFinal123!',
    ]);
    expect($r, 200, 'staff changes the temporary password');
    $staff->absorb($r);

    $r = $staff->request('GET', '/api/me');
    expect($r, 200, 'staff session works after password change');
    check((int) ($r['json']['branch_id'] ?? 0) === $mainId, 'staff branch is always the assigned branch');

    $r = $staff->request('GET', '/api/users');
    expect($r, 403, 'staff cannot list users');

    $r = $staff->request('POST', '/api/branches', ['name' => 'Nope', 'code' => 'NOPE']);
    expect($r, 403, 'staff cannot create branches');

    /* --------------------------------------------------------------------- */
    section('Customer signup and customer-only permissions');
    /* --------------------------------------------------------------------- */

    $r = $customer->request('POST', '/api/register', [
        'name' => 'Test Customer',
        'username' => 'custtest',
        'contact' => '09171234567',
        'address' => '123 Test Street',
        'password' => 'Customer123!',
    ]);
    expect($r, 201, 'customer signs up');
    $customer->absorb($r);
    $customerId = (int) ($r['json']['customer_id'] ?? 0);
    check(($r['json']['role'] ?? '') === 'customer', 'signup creates a customer role');
    check($customerId > 0, 'signup links a customer record');

    $dupe = new ApiClient($base, 'dupe');
    $r = $dupe->request('POST', '/api/register', [
        'name' => 'Test Customer',
        'username' => 'custtest',
        'contact' => '09171234567',
        'address' => '123 Test Street',
        'password' => 'Customer123!',
    ]);
    expect($r, 409, 'duplicate customer username is rejected');
    check(($r['json']['code'] ?? '') === 'username_taken', 'duplicate signup returns username_taken');

    $r = $customer->request('GET', '/api/users');
    expect($r, 403, 'customer cannot list users');
    $r = $customer->request('GET', '/api/sales');
    expect($r, 403, 'customer cannot read the sales register');

    /* --------------------------------------------------------------------- */
    section('Customer home branch and staff scoping');
    /* --------------------------------------------------------------------- */

    // Owner-created customers belong to the branch selected for the write.
    $r = $owner->request('POST', '/api/customers', [
        'name' => 'North Only Customer',
        'contact' => '09179999999',
    ], ['X-Branch-Id: ' . $northId]);
    expect($r, 201, 'owner creates a customer in the north branch');
    $northCustomerId = (int) ($r['json']['id'] ?? 0);
    check($northCustomerId > 0, 'north customer has an id');

    $r = $staff->request('PUT', '/api/customers/' . $northCustomerId, [
        'name' => 'Tampered Name',
    ], ['X-Branch-Id: ' . $northId]);
    expect($r, 403, 'main staff cannot edit a north-branch customer even with a north header');
    check(($r['json']['code'] ?? '') === 'branch_forbidden', 'cross-branch customer edit returns branch_forbidden');

    $r = $staff->request('DELETE', '/api/customers/' . $northCustomerId);
    expect($r, 403, 'main staff cannot deactivate a north-branch customer');

    // Public signup has no home branch and no main-branch activity yet.
    $r = $staff->request('PUT', '/api/customers/' . $customerId, [
        'name' => 'Tampered Name',
    ]);
    expect($r, 403, 'main staff cannot edit an unaffiliated customer with no main activity');

    $r = $staff->request('GET', '/api/customers?q=North%20Only');
    expect($r, 200, 'staff customer list loads');
    $staffSeesNorth = false;
    foreach (is_array($r['json']) ? $r['json'] : [] as $row) {
        if ((int) ($row['id'] ?? 0) === $northCustomerId) {
            $staffSeesNorth = true;
        }
    }
    check(!$staffSeesNorth, 'main staff list excludes a north-only customer');

    $r = $owner->request('GET', '/api/customers?q=North%20Only', null, ['X-Branch-Id: all']);
    expect($r, 200, 'owner lists customers across all branches');
    $ownerNorth = null;
    foreach (is_array($r['json']) ? $r['json'] : [] as $row) {
        if ((int) ($row['id'] ?? 0) === $northCustomerId) {
            $ownerNorth = $row;
        }
    }
    check($ownerNorth !== null, 'owner all-branch list includes the north customer');
    check((int) ($ownerNorth['branch_id'] ?? 0) === $northId, 'customer payload exposes the home branch');

    $r = $owner->request('PUT', '/api/customers/' . $northCustomerId, [
        'name' => 'North Customer Updated',
    ]);
    expect($r, 200, 'owner can update a customer without a concrete branch header');

    $r = $owner->request('PUT', '/api/customers/' . $northCustomerId, [
        'branch_id' => $mainId,
        'name' => 'North Customer Moved',
    ]);
    expect($r, 200, 'owner can reassign a customer home branch');
    $r = $staff->request('PUT', '/api/customers/' . $northCustomerId, [
        'name' => 'Moved Customer Updated',
    ]);
    expect($r, 200, 'staff can edit a customer after it is moved to their branch');

    $r = $staff->request('POST', '/api/customers', ['name' => 'Main Temp Customer']);
    expect($r, 201, 'staff creates a customer in their own branch');
    $tempCustomerId = (int) ($r['json']['id'] ?? 0);
    $r = $staff->request('DELETE', '/api/customers/' . $tempCustomerId);
    expect($r, 200, 'staff deactivates a customer in their own branch');


    /* --------------------------------------------------------------------- */
    section('Opening stock and stock guards');
    /* --------------------------------------------------------------------- */

    $r = $owner->request('POST', '/api/adjustments', [
        'size_id' => $size1,
        'reason' => 'Opening count',
        'note' => 'Main opening count',
        'full' => 50,
        'empty' => 10,
    ], ['X-Branch-Id: ' . $mainId]);
    expect($r, 201, 'owner records main opening stock');

    $r = $owner->request('POST', '/api/adjustments', [
        'size_id' => $size1,
        'reason' => 'Opening count',
        'note' => 'North opening count',
        'full' => 5,
        'empty' => 2,
    ], ['X-Branch-Id: ' . $northId]);
    expect($r, 201, 'owner records north opening stock');

    $r = $owner->request('GET', '/api/reports/stock?branch_id=' . $mainId);
    expect($r, 200, 'stock report loads for the main branch');
    $mainStock = null;
    foreach (($r['json']['rows'] ?? []) as $row) {
        if ((int) $row['size_id'] === $size1 && (int) $row['branch_id'] === $mainId) {
            $mainStock = $row;
        }
    }
    check($mainStock !== null, 'main stock row exists for the first size');
    check((int) ($mainStock['full_qty'] ?? -1) === 50, 'main opening full stock is 50');
    check((int) ($mainStock['empty_qty'] ?? -1) === 10, 'main opening empty stock is 10');

    /* --------------------------------------------------------------------- */
    section('Direct sales, branch header locking and void reversal');
    /* --------------------------------------------------------------------- */

    $r = $staff->request('POST', '/api/sales', [
        'pay' => 'cash',
        'items' => [['size_id' => $size1, 'qty' => 3, 'back' => 2]],
    ], ['X-Branch-Id: ' . $northId]);
    expect($r, 201, 'staff sale succeeds while sending a different branch header');
    $saleId = (int) ($r['json']['id'] ?? 0);
    check((int) ($r['json']['branch_id'] ?? 0) === $mainId, 'staff sale is forced into the staff branch');
    check(abs((float) ($r['json']['total_amount'] ?? 0) - 300.0) < 0.001, 'staff sale uses the main branch price (3 x 100)');

    $r = $owner->request('DELETE', '/api/sales/' . $saleId, ['reason' => 'Test void reversal'], ['X-Branch-Id: ' . $mainId]);
    expect($r, 200, 'owner voids the sale');
    $r = $owner->request('DELETE', '/api/sales/' . $saleId, ['reason' => 'Already voided'], ['X-Branch-Id: ' . $mainId]);
    expect($r, 409, 'voiding the same sale twice is rejected');
    check(($r['json']['code'] ?? '') === 'already_void', 'second void returns already_void');

    $r = $owner->request('GET', '/api/reports/stock?branch_id=' . $mainId);
    expect($r, 200, 'stock report reloads after void');
    $mainStock = null;
    foreach (($r['json']['rows'] ?? []) as $row) {
        if ((int) $row['size_id'] === $size1 && (int) $row['branch_id'] === $mainId) {
            $mainStock = $row;
        }
    }
    check((int) ($mainStock['full_qty'] ?? -1) === 50, 'void restores full stock to 50');
    check((int) ($mainStock['empty_qty'] ?? -1) === 10, 'void restores empty stock to 10');
    check((int) ($mainStock['out_qty'] ?? -1) === 0, 'void restores cylinders-with-customers to 0');

    $r = $staff->request('POST', '/api/sales', [
        'pay' => 'cash',
        'items' => [['size_id' => $size1, 'qty' => 200]],
    ]);
    expect($r, 409, 'overselling is rejected');
    check(($r['json']['code'] ?? '') === 'insufficient_stock', 'oversell returns insufficient_stock');

    /* --------------------------------------------------------------------- */
    section('Customer multi-item orders, edits, cancellation and limits');
    /* --------------------------------------------------------------------- */

    $r = $customer->request('POST', '/api/my/orders', [
        'branch_id' => $northId,
        'pay' => 'cash',
        'requested_date' => $today,
        'time_slot' => '09:00-12:00',
        'delivery_address' => '123 Test Street',
        'valve_type' => 'snap_on',
        'notes' => 'Test order',
        'items' => [['size_id' => $size1, 'qty' => 2, 'deposit_amount' => 0]],
    ]);
    expect($r, 201, 'customer creates an order at the north branch');
    $orderId = (int) ($r['json']['id'] ?? 0);
    check(($r['json']['status'] ?? '') === 'pending', 'new order is pending');
    check(abs((float) ($r['json']['total_amount'] ?? 0) - 220.0) < 0.001, 'order uses the north branch price (2 x 110)');
    check((int) ($r['json']['branch_id'] ?? 0) === $northId, 'order keeps the selected branch');

    $r = $customer->request('PATCH', '/api/my/orders/' . $orderId, [
        'notes' => 'Updated note',
        'items' => [['size_id' => $size1, 'qty' => 2, 'deposit_amount' => 0]],
    ]);
    expect($r, 200, 'customer edits a pending order');
    check(($r['json']['notes'] ?? '') === 'Updated note', 'pending order edit saves the new notes');
    check(abs((float) ($r['json']['total_amount'] ?? 0) - 220.0) < 0.001, 'pending order edit re-prices at the branch price');

    $r = $customer->request('POST', '/api/my/orders', [
        'branch_id' => $northId,
        'pay' => 'cash',
        'requested_date' => $today,
        'items' => [['size_id' => $size1, 'qty' => 1]],
    ]);
    expect($r, 201, 'customer creates a second support order');
    $order2 = (int) ($r['json']['id'] ?? 0);

    $r = $customer->request('POST', '/api/my/orders/' . $order2 . '/cancel');
    expect($r, 200, 'customer cancels a pending order');
    $r = $customer->request('PATCH', '/api/my/orders/' . $order2, [
        'items' => [['size_id' => $size1, 'qty' => 1]],
    ]);
    expect($r, 409, 'cancelled order can no longer be edited');
    check(($r['json']['code'] ?? '') === 'order_locked', 'edit after cancel returns order_locked');
    $r = $customer->request('POST', '/api/my/orders/' . $order2 . '/cancel');
    expect($r, 409, 'cancelled order cannot be cancelled again');

    $r = $customer->request('POST', '/api/my/orders', [
        'branch_id' => $northId,
        'pay' => 'cash',
        'requested_date' => $today,
        'items' => [['size_id' => $size1, 'qty' => 50]],
    ]);
    expect($r, 422, 'order above the branch quantity limit is rejected');
    check(($r['json']['code'] ?? '') === 'order_limit', 'quantity limit returns order_limit');

    /* --------------------------------------------------------------------- */
    section('Order approval/delivery, locked prices and branch access');
    /* --------------------------------------------------------------------- */

    $r = $owner->request('PUT', '/api/branches/' . $northId . '/prices', [
        'prices' => [['size_id' => $size1, 'price' => 150]],
    ]);
    expect($r, 200, 'owner changes the north branch price after the order was placed');

    $r = $staff->request('POST', '/api/orders/' . $orderId . '/approve', null, ['X-Branch-Id: ' . $mainId]);
    expect($r, 403, 'main-branch staff cannot approve a north-branch order');
    check(($r['json']['code'] ?? '') === 'branch_forbidden', 'cross-branch approve returns branch_forbidden');

    $r = $owner->request('POST', '/api/orders/' . $orderId . '/approve', null, ['X-Branch-Id: ' . $northId]);
    expect($r, 200, 'owner approves the north order with an explicit branch');
    $r = $owner->request('POST', '/api/orders/' . $orderId . '/approve', null, ['X-Branch-Id: ' . $northId]);
    expect($r, 409, 'approving an already-approved order is rejected');

    $r = $owner->request('POST', '/api/orders/' . $orderId . '/deliver', [
        'items' => [['size_id' => $size1, 'empties_received' => 1]],
        'proof_of_delivery' => 'pod.jpg',
    ], ['X-Branch-Id: ' . $northId]);
    expect($r, 200, 'owner delivers the order');
    $deliveredSaleId = (int) ($r['json']['sale_id'] ?? 0);
    check($deliveredSaleId > 0, 'delivery creates a sale');
    check(abs((float) ($r['json']['total_amount'] ?? 0) - 220.0) < 0.001, 'delivered sale keeps the original order price (2 x 110), not 2 x 150');

    $r = $owner->request('POST', '/api/orders/' . $orderId . '/deliver', [], ['X-Branch-Id: ' . $northId]);
    expect($r, 409, 'delivering the same order twice is rejected');
    check(($r['json']['code'] ?? '') === 'order_locked', 'second delivery returns order_locked');

    $r = $owner->request('GET', '/api/sales/' . $deliveredSaleId);
    expect($r, 200, 'delivered sale can be read');
    $linePrice = (float) ($r['json']['items'][0]['unit_price'] ?? 0);
    check(abs($linePrice - 110.0) < 0.001, 'sale line keeps the price locked when the order was placed');

    $r = $customer->request('GET', '/api/my/orders');
    expect($r, 200, 'customer lists their own orders');
    check(is_array($r['json']) && count($r['json']) >= 2, 'customer sees their orders');

    /* --------------------------------------------------------------------- */
    section('Credit sale, payment validation and unpaid report');
    /* --------------------------------------------------------------------- */

    $r = $staff->request('POST', '/api/sales', [
        'pay' => 'credit',
        'customer_id' => $customerId,
        'items' => [['size_id' => $size1, 'qty' => 1, 'back' => 0]],
    ]);
    expect($r, 201, 'staff records a credit sale to the customer');
    $creditSaleId = (int) ($r['json']['id'] ?? 0);
    check(abs((float) ($r['json']['total_amount'] ?? 0) - 100.0) < 0.001, 'credit sale uses the main branch price');

    // The customer was created by public signup (no home branch), but this
    // main-branch transaction now makes it manageable by main staff.
    $r = $staff->request('PUT', '/api/customers/' . $customerId, [
        'name' => 'Test Customer',
        'contact' => '09171234567',
    ]);
    expect($r, 200, 'staff can edit a customer after a transaction in their branch');

    $r = $staff->request('GET', '/api/customers?q=Test%20Customer');
    expect($r, 200, 'staff customer list reloads after the sale');
    $staffSeesCustomer = false;
    foreach (is_array($r['json']) ? $r['json'] : [] as $row) {
        if ((int) ($row['id'] ?? 0) === $customerId) {
            $staffSeesCustomer = true;
        }
    }
    check($staffSeesCustomer, 'main staff list includes a customer with main-branch activity');

    $r = $owner->request('GET', '/api/reports/unpaid?branch_id=' . $mainId);
    expect($r, 200, 'unpaid report loads');
    $unpaidRow = null;
    foreach (($r['json']['rows'] ?? []) as $row) {
        if ((int) ($row['id'] ?? 0) === $customerId) {
            $unpaidRow = $row;
        }
    }
    check($unpaidRow !== null, 'unpaid report includes the customer');
    check(abs((float) ($unpaidRow['balance'] ?? 0) - 100.0) < 0.001, 'unpaid balance is 100');

    $r = $staff->request('POST', '/api/payments', [
        'customer_id' => $customerId,
        'amount' => 150,
        'method' => 'cash',
    ]);
    expect($r, 422, 'payment larger than the balance is rejected');
    check(($r['json']['code'] ?? '') === 'payment_too_large', 'overpayment returns payment_too_large');

    $r = $staff->request('POST', '/api/payments', [
        'customer_id' => $customerId,
        'amount' => 100,
        'method' => 'gcash',
        'reference_no' => 'GC-1001',
    ]);
    expect($r, 201, 'staff records a valid partial method payment');
    check(abs((float) ($r['json']['balance_after'] ?? -1)) < 0.001, 'payment clears the balance');

    $r = $staff->request('POST', '/api/payments', [
        'customer_id' => $customerId,
        'amount' => 10,
        'method' => 'cash',
    ]);
    expect($r, 409, 'payment with no remaining balance is rejected');
    check(($r['json']['code'] ?? '') === 'no_balance', 'zero balance returns no_balance');

    $r = $owner->request('GET', '/api/reports/unpaid?branch_id=' . $mainId);
    expect($r, 200, 'unpaid report reloads after payment');
    $stillUnpaid = false;
    foreach (($r['json']['rows'] ?? []) as $row) {
        if ((int) ($row['id'] ?? 0) === $customerId) {
            $stillUnpaid = true;
        }
    }
    check(!$stillUnpaid, 'fully paid customer disappears from unpaid report');

    $r = $customer->request('GET', '/api/my/account');
    expect($r, 200, 'customer account loads');
    check(abs((float) ($r['json']['balance'] ?? -1)) < 0.001, 'customer balance is zero after payment');
    check((int) ($r['json']['empties_owed'] ?? -1) === 2, 'customer owes 2 empties (1 from order + 1 from credit sale)');

    /* --------------------------------------------------------------------- */
    section('Inter-branch transfers');
    /* --------------------------------------------------------------------- */

    $transferBody = [
        'from_branch_id' => $mainId,
        'to_branch_id' => $northId,
        'notes' => 'Rebalance stock',
        'idempotency_key' => 'test-transfer-1',
        'items' => [['size_id' => $size1, 'qty_full' => 2, 'qty_empty' => 1]],
    ];
    $r = $owner->request('POST', '/api/transfers', $transferBody);
    expect($r, 201, 'owner creates a transfer');
    $transferId = (int) ($r['json']['id'] ?? 0);
    check(($r['json']['status'] ?? '') === 'requested', 'new transfer is requested');

    $r = $owner->request('POST', '/api/transfers', $transferBody);
    expect($r, 200, 'reposting the same idempotency key returns the existing transfer');
    check((int) ($r['json']['id'] ?? 0) === $transferId, 'idempotent transfer returns the same id');
    $count = (int) $pdo->query("SELECT COUNT(*) FROM stock_transfers WHERE idempotency_key='test-transfer-1'")->fetchColumn();
    check($count === 1, 'idempotency key created exactly one transfer row');

    $r = $owner->request('POST', '/api/transfers/' . $transferId . '/approve');
    expect($r, 200, 'owner approves the transfer');
    check(($r['json']['status'] ?? '') === 'approved', 'transfer is approved');
    $r = $owner->request('POST', '/api/transfers/' . $transferId . '/approve');
    expect($r, 200, 'approving an approved transfer is idempotent');

    $r = $staff->request('POST', '/api/transfers/' . $transferId . '/ship');
    expect($r, 200, 'source-branch staff ships the transfer');
    check(($r['json']['status'] ?? '') === 'in_transit', 'transfer is in transit');

    $r = $staff->request('POST', '/api/transfers/' . $transferId . '/cancel', ['reason' => 'Too late']);
    expect($r, 409, 'shipped transfer cannot be cancelled');
    check(($r['json']['code'] ?? '') === 'transfer_locked', 'shipped cancel returns transfer_locked');

    $r = $staff->request('POST', '/api/transfers/' . $transferId . '/receive');
    expect($r, 403, 'source-branch staff cannot receive the transfer');
    check(($r['json']['code'] ?? '') === 'branch_forbidden', 'wrong-branch receive returns branch_forbidden');

    $r = $owner->request('POST', '/api/transfers/' . $transferId . '/receive');
    expect($r, 200, 'owner receives the transfer');
    check(($r['json']['status'] ?? '') === 'received', 'transfer is received');
    $r = $owner->request('POST', '/api/transfers/' . $transferId . '/receive');
    expect($r, 200, 'receiving a received transfer is idempotent');

    $r = $owner->request('POST', '/api/transfers', [
        'from_branch_id' => $northId,
        'to_branch_id' => $mainId,
        'items' => [['size_id' => $size1, 'qty_full' => 1]],
    ]);
    expect($r, 201, 'owner creates a second transfer');
    $transfer2 = (int) ($r['json']['id'] ?? 0);
    $r = $owner->request('POST', '/api/transfers/' . $transfer2 . '/approve');
    expect($r, 200, 'owner approves the second transfer');
    $r = $owner->request('POST', '/api/transfers/' . $transfer2 . '/cancel', ['reason' => 'No longer needed']);
    expect($r, 200, 'unshipped transfer can be cancelled');
    check(($r['json']['status'] ?? '') === 'cancelled', 'second transfer is cancelled');

    $r = $owner->request('GET', '/api/reports/stock?branch_id=' . $mainId);
    expect($r, 200, 'main stock reloads after transfers');
    $mainStock = null;
    foreach (($r['json']['rows'] ?? []) as $row) {
        if ((int) $row['size_id'] === $size1 && (int) $row['branch_id'] === $mainId) {
            $mainStock = $row;
        }
    }
    check((int) ($mainStock['full_qty'] ?? -1) === 47, 'main full stock is 47 after credit sale and transfer out');
    check((int) ($mainStock['empty_qty'] ?? -1) === 9, 'main empty stock is 9 after transfer out');
    check((int) ($mainStock['out_qty'] ?? -1) === 1, 'main cylinders-with-customers is 1 after credit sale');

    $r = $owner->request('GET', '/api/reports/stock?branch_id=' . $northId);
    expect($r, 200, 'north stock reloads after delivery and transfer');
    $northStock = null;
    foreach (($r['json']['rows'] ?? []) as $row) {
        if ((int) $row['size_id'] === $size1 && (int) $row['branch_id'] === $northId) {
            $northStock = $row;
        }
    }
    check((int) ($northStock['full_qty'] ?? -1) === 5, 'north full stock is 5 (5 - 2 delivered + 2 received)');
    check((int) ($northStock['empty_qty'] ?? -1) === 4, 'north empty stock is 4 (2 + 1 returned + 1 received)');
    check((int) ($northStock['out_qty'] ?? -1) === 1, 'north cylinders-with-customers is 1');

    /* --------------------------------------------------------------------- */
    section('Branch deactivation guards');
    /* --------------------------------------------------------------------- */

    $r = $owner->request('POST', '/api/branches', [
        'name' => 'Temporary Branch',
        'code' => 'TEMP',
        'max_order_qty' => 5,
    ]);
    expect($r, 201, 'owner creates a temporary branch');
    $tempId = (int) ($r['json']['id'] ?? 0);

    $r = $owner->request('POST', '/api/branches/' . $tempId . '/deactivate');
    expect($r, 200, 'empty branch can be deactivated');
    check((int) ($r['json']['is_active'] ?? -1) === 0, 'temporary branch is inactive');

    $r = $customer->request('POST', '/api/my/orders', [
        'branch_id' => $tempId,
        'pay' => 'cash',
        'requested_date' => $today,
        'items' => [['size_id' => $size1, 'qty' => 1]],
    ]);
    expect($r, 409, 'customer cannot order from an inactive branch');
    check(($r['json']['code'] ?? '') === 'branch_inactive', 'inactive branch order returns branch_inactive');

    $r = $owner->request('POST', '/api/branches/' . $tempId . '/activate');
    expect($r, 200, 'owner reactivates the temporary branch');
    check((int) ($r['json']['is_active'] ?? -1) === 1, 'temporary branch is active again');

    $r = $owner->request('POST', '/api/branches/' . $mainId . '/deactivate');
    expect($r, 409, 'branch with stock and staff cannot be deactivated');
    check(in_array(($r['json']['code'] ?? ''), ['branch_has_stock', 'branch_has_staff', 'branch_has_open_work'], true), 'main deactivation is blocked by its open work');

    /* --------------------------------------------------------------------- */
    section('Reports and CSV exports');
    /* --------------------------------------------------------------------- */

    $r = $owner->request('GET', '/api/reports/daily?from=' . $today . '&to=' . $today);
    expect($r, 200, 'daily report loads');
    check(abs((float) ($r['json']['totals']['total_sales'] ?? 0) - 320.0) < 0.001, 'daily total sales are 320 (100 credit + 220 delivery)');
    check(isset($r['json']['rows'][0]['cash_collected']), 'daily report includes cash collected');

    $r = $owner->request('GET', '/api/reports/sales?from=' . $today . '&to=' . $today . '&format=csv');
    expect($r, 200, 'sales register CSV downloads');
    check(strpos((string) ($r['headers']['content-type'] ?? ''), 'text/csv') !== false, 'CSV response has text/csv content type');
    check(strncmp((string) $r['body'], "\xEF\xBB\xBF", 3) === 0, 'CSV starts with a UTF-8 BOM');
    check(strpos((string) $r['body'], 'Sale no.') !== false, 'CSV contains the expected header row');
    check(strpos((string) ($r['headers']['content-disposition'] ?? ''), 'attachment') !== false, 'CSV is served as an attachment');

    $r = $staff->request('GET', '/api/reports/branch-comparison?from=' . $today . '&to=' . $today);
    expect($r, 403, 'staff cannot read the owner branch comparison');

    $r = $owner->request('GET', '/api/reports/branch-comparison?from=' . $today . '&to=' . $today);
    expect($r, 200, 'owner loads the branch comparison');
    check(is_array($r['json']['rows'] ?? null) && count($r['json']['rows']) >= 3, 'branch comparison includes all branches');
    check(abs((float) ($r['json']['totals']['sales_total'] ?? 0) - 320.0) < 0.001, 'branch comparison sales total is 320');

    $r = $owner->request('GET', '/api/reports/sales?from=bad-date');
    expect($r, 422, 'invalid report date is rejected');
    check(($r['json']['code'] ?? '') === 'validation_error', 'bad report date returns validation_error');

    $r = $owner->request('GET', '/api/reports/empties');
    expect($r, 200, 'empties report loads');
    $emptiesRow = null;
    foreach (($r['json']['rows'] ?? []) as $row) {
        if ((int) ($row['id'] ?? 0) === $customerId) {
            $emptiesRow = $row;
        }
    }
    check($emptiesRow !== null, 'empties report includes the customer');
    check((int) ($emptiesRow['empties_owed'] ?? -1) === 2, 'empties report shows 2 owed');

    /* --------------------------------------------------------------------- */
    section('Branch scoping on reads and audit trail');
    /* --------------------------------------------------------------------- */

    $r = $staff->request('GET', '/api/sales', null, ['X-Branch-Id: ' . $northId]);
    expect($r, 200, 'staff sales list loads while sending a north header');
    $allMain = true;
    foreach (($r['json'] ?? []) as $sale) {
        if ((int) ($sale['branch_id'] ?? 0) !== $mainId) {
            $allMain = false;
        }
    }
    check($allMain, 'staff sales list is restricted to the staff branch even with a different header');

    $r = $owner->request('GET', '/api/sales', null, ['X-Branch-Id: ' . $northId]);
    expect($r, 200, 'owner sales list loads for one branch');
    $allNorth = true;
    foreach (($r['json'] ?? []) as $sale) {
        if ((int) ($sale['branch_id'] ?? 0) !== $northId) {
            $allNorth = false;
        }
    }
    check($allNorth, 'owner one-branch sales list is restricted to that branch');

    $r = $owner->request('GET', '/api/sales', null, ['X-Branch-Id: all']);
    expect($r, 200, 'owner sales list loads for all branches');
    $branchIds = array_unique(array_map(static fn($s) => (int) $s['branch_id'], is_array($r['json']) ? $r['json'] : []));
    check(in_array($mainId, $branchIds, true) && in_array($northId, $branchIds, true), 'all-branch sales list contains main and north');

    $r = $owner->request('GET', '/api/audit');
    expect($r, 200, 'owner reads the audit trail');
    check(is_array($r['json']) && count($r['json']) > 10, 'audit trail has entries');
    $entities = array_unique(array_map(static fn($a) => (string) ($a['entity'] ?? ''), is_array($r['json']) ? $r['json'] : []));
    check(in_array('sale', $entities, true) && in_array('transfer', $entities, true), 'audit trail records sales and transfers');

    $r = $staff->request('GET', '/api/audit');
    expect($r, 403, 'staff cannot read the audit trail');

    /* --------------------------------------------------------------------- */
    section('Login rate limiting and session isolation');
    /* --------------------------------------------------------------------- */

    $ghost = new ApiClient($base, 'ghost');
    for ($i = 1; $i <= 5; $i++) {
        $r = $ghost->login('ghost_user_xyz', 'WrongPass123!');
        expect($r, 401, 'unknown-user login attempt ' . $i . ' fails normally');
    }
    $r = $ghost->login('ghost_user_xyz', 'WrongPass123!');
    expect($r, 429, 'repeated unknown-user login is rate limited');
    check(($r['json']['code'] ?? '') === 'rate_limited', 'rate limit returns rate_limited');

    $r = $owner->request('GET', '/api/me');
    expect($r, 200, 'owner session survives other clients being rate limited');
    check((int) ($r['json']['id'] ?? 0) > 0, 'owner session is still authenticated');

    $r = $owner->request('POST', '/api/logout');
    expect($r, 200, 'owner logs out');
    $r = $owner->request('GET', '/api/me');
    expect($r, 401, 'logged-out session is rejected');
} finally {
    if ($managedServer !== null) {
        stop_server($managedServer);
    }
}

/* ------------------------------------------------------------------------- */
/* Summary                                                                   */
/* ------------------------------------------------------------------------- */

$pass = (int) $GLOBALS['pass'];
$fail = (int) $GLOBALS['fail'];
$failures = $GLOBALS['failures'];

echo "\n" . str_repeat('-', 60) . "\n";
echo "{$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $failure) {
        echo '  - ' . $failure . "\n";
    }
    exit(1);
}
echo "All API tests passed.\n";
exit(0);
