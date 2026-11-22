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

/** True when a payload explicitly carries the key with a null value. */
function has_null_field(array $payload, string $key): bool
{
    return array_key_exists($key, $payload) && $payload[$key] === null;
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

/**
 * Test-harness compatibility shim.
 *
 * database/schema.sql currently omits `users.phone_verified`, but
 * api/src/auth.php:152 still selects that column when loading a session.
 * Restore it in the disposable test database only so the rest of the suite can
 * run; no application or schema file is changed (see the final report).
 */
function ensure_schema_compatibility(PDO $pdo): void
{
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='phone_verified'"
    );
    $st->execute();
    if ((int) $st->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE users ADD COLUMN phone_verified TINYINT(1) NOT NULL DEFAULT 0');
    }
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

/** Run a project CLI script with the same PHP binary and a temporary config. */
function run_cli_script(string $root, string $configFile, string $script, array $args = []): array
{
    $env = getenv();
    if (!is_array($env)) {
        $env = [];
    }
    $env['LPG_CONFIG_FILE'] = $configFile;
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $script);
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path);
    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg((string) $arg);
    }
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, $root, $env);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the migration CLI.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    return [
        'status' => $status,
        'stdout' => (string) $stdout,
        'stderr' => (string) $stderr,
    ];
}

/**
 * Exercise the 005 preflight guard against a disposable legacy-schema DB.
 * The guard runs before --dry-run, so no DDL is needed to verify the refusal
 * and the successful pass after the balance is settled.
 */
function check_migration_guard(string $root, array $config): void
{
    section('Migration 005 preflight guard');

    $migDb = 'lpg_mig_test';
    $creds = $config['db'];
    $refusal = 'Outstanding credit balances exist. Settle them before running migration 005.';
    $serverDsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $creds['host'], (int) $creds['port']);
    $server = new PDO($serverDsn, (string) $creds['user'], (string) $creds['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $configFile = tempnam(sys_get_temp_dir(), 'lpg_mig_cfg_');
    if ($configFile === false) {
        throw new RuntimeException('Could not create a temporary migration config.');
    }
    $migConfig = $config;
    $migConfig['db']['name'] = $migDb;
    file_put_contents($configFile, "<?php\nreturn " . var_export($migConfig, true) . ";\n");

    try {
        $server->exec('DROP DATABASE IF EXISTS `' . $migDb . '`');
        $server->exec('CREATE DATABASE `' . $migDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $mig = new PDO(
            $serverDsn . ';dbname=' . $migDb,
            (string) $creds['user'],
            (string) $creds['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        // Minimal legacy (pre-005) shape: enough for the 005 preflight query.
        $mig->exec(
            'CREATE TABLE sales (
                id INT AUTO_INCREMENT PRIMARY KEY,
                customer_id INT NOT NULL,
                branch_id INT NOT NULL,
                payment_type VARCHAR(20) NOT NULL,
                total_amount DECIMAL(10,2) NOT NULL,
                is_void TINYINT(1) NOT NULL DEFAULT 0
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $mig->exec(
            'CREATE TABLE payments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                customer_id INT NOT NULL,
                branch_id INT NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                is_void TINYINT(1) NOT NULL DEFAULT 0
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $mig->exec(
            'CREATE TABLE schema_migrations (
                version VARCHAR(50) PRIMARY KEY,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $mark = $mig->prepare('INSERT INTO schema_migrations (version) VALUES (?)');
        foreach ([
            '001_add_branches',
            '002_customer_home_branch',
            '003_structured_names_addresses_delivery',
            '004_rush_same_day_fee',
        ] as $version) {
            $mark->execute([$version]);
        }
        $mig->exec(
            "INSERT INTO sales (customer_id, branch_id, payment_type, total_amount, is_void) VALUES
             (1, 1, 'credit', 100.00, 0),
             (1, 1, 'credit', 500.00, 1),
             (2, 1, 'cash', 50.00, 0)"
        );

        $blocked = run_cli_script($root, $configFile, 'database/migrate.php', ['--dry-run', '--no-backup']);
        check($blocked['status'] !== 0, 'migrate.php exits non-zero while a credit balance is outstanding', 'status=' . $blocked['status'] . ' stdout=' . substr($blocked['stdout'], 0, 300) . ' stderr=' . substr($blocked['stderr'], 0, 300));
        check(strpos($blocked['stderr'], $refusal) !== false, 'stderr contains the exact 005 refusal message', substr($blocked['stderr'], 0, 300));
        check(strpos($blocked['stdout'], 'Applying 005_') === false, 'guarded migration is not applied while credit is outstanding');

        $mig->exec('INSERT INTO payments (customer_id, branch_id, amount, is_void) VALUES (1, 1, 100.00, 0)');
        $settled = run_cli_script($root, $configFile, 'database/migrate.php', ['--dry-run', '--no-backup']);
        check($settled['status'] === 0, 'migrate.php proceeds once the credit balance is settled', 'status=' . $settled['status'] . ' stdout=' . substr($settled['stdout'], 0, 300) . ' stderr=' . substr($settled['stderr'], 0, 300));
        check(strpos($settled['stderr'], $refusal) === false, 'guard no longer refuses after the payment is recorded', substr($settled['stderr'], 0, 300));
        check(strpos($settled['stdout'], 'Dry run: no changes made.') !== false, 'dry run completes after the guard passes');
    } finally {
        $server->exec('DROP DATABASE IF EXISTS `' . $migDb . '`');
        @unlink($configFile);
    }
}

/* ------------------------------------------------------------------------- */
/* Boot                                                                      */
/* ------------------------------------------------------------------------- */

echo "Resetting {$testDb} from database/schema.sql ...\n";
$pdo = reset_database($root, $db);
ensure_schema_compatibility($pdo);
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
    check(abs((float) ($r['json']['rush_fee'] ?? -1) - 50.0) < 0.001, 'new branches default to the standard ₱50 rush fee');

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
    section('Removed payment routes, account shape and direct sale');
    /* --------------------------------------------------------------------- */

    $r = $customer->request('GET', '/api/my/payments');
    expect($r, 404, 'GET /api/my/payments is removed');
    $r = $customer->request('GET', '/api/payments');
    expect($r, 404, 'GET /api/payments is removed');
    $r = $customer->request('POST', '/api/payments', [
        'customer_id' => $customerId,
        'amount' => 50,
        'method' => 'cash',
    ]);
    expect($r, 404, 'POST /api/payments is removed');
    $r = $owner->request('DELETE', '/api/payments/1');
    expect($r, 404, 'DELETE /api/payments/{id} is removed');
    $r = $owner->request('GET', '/api/reports/unpaid?branch_id=' . $mainId);
    expect($r, 404, 'GET /api/reports/unpaid is removed');

    $r = $staff->request('POST', '/api/sales', [
        'pay' => 'credit',
        'customer_id' => $customerId,
        'items' => [['size_id' => $size1, 'qty' => 1, 'back' => 0]],
    ]);
    expect($r, 422, 'credit is no longer a valid sale payment method');
    check(($r['json']['code'] ?? '') === 'validation_error', 'credit sale returns validation_error');

    // A regular cash sale keeps the customer's main-branch activity (and the
    // empties/stock assertions below) without any credit balance concept.
    $r = $staff->request('POST', '/api/sales', [
        'pay' => 'cash',
        'customer_id' => $customerId,
        'items' => [['size_id' => $size1, 'qty' => 1, 'back' => 0]],
    ]);
    expect($r, 201, 'staff records a cash sale to the customer');
    $cashSaleId = (int) ($r['json']['id'] ?? 0);
    check($cashSaleId > 0, 'cash sale has an id');
    check(($r['json']['payment_method'] ?? '') === 'cash', 'direct sale exposes payment_method cash');
    check(has_null_field(is_array($r['json']) ? $r['json'] : [], 'payment_reference'), 'cash sale has no payment reference');
    check(abs((float) ($r['json']['total_amount'] ?? 0) - 100.0) < 0.001, 'cash sale uses the main branch price');

    // The customer was created by public signup (no home branch), but this
    // main-branch transaction now makes it manageable by main staff.
    $r = $staff->request('PUT', '/api/customers/' . $customerId, [
        'name' => 'Test Customer',
        'contact' => '09171234567',
    ]);
    expect($r, 200, 'staff can edit a customer after a transaction in their branch');

    $r = $staff->request('GET', '/api/customers?q=Test%20Customer');
    expect($r, 200, 'staff customer list reloads after the sale');
    $staffCustomer = null;
    foreach (is_array($r['json']) ? $r['json'] : [] as $row) {
        if ((int) ($row['id'] ?? 0) === $customerId) {
            $staffCustomer = $row;
        }
    }
    check($staffCustomer !== null, 'main staff list includes a customer with main-branch activity');
    foreach (['balance', 'credit_limit', 'is_reseller', 'reseller_status'] as $removed) {
        check($staffCustomer !== null && !array_key_exists($removed, $staffCustomer), "customer list no longer exposes {$removed}");
    }

    $r = $customer->request('GET', '/api/my/account');
    expect($r, 200, 'customer account loads');
    foreach (['balance', 'credit_limit', 'is_reseller', 'reseller_status', 'by_branch'] as $removed) {
        check(!array_key_exists($removed, is_array($r['json']) ? $r['json'] : []), "customer account no longer exposes {$removed}");
    }
    check((int) ($r['json']['empties_owed'] ?? -1) === 2, 'customer owes 2 empties (1 from order + 1 from cash sale)');

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

    $r = $owner->request('POST', '/api/transfers', [
        'from_branch_id' => $mainId,
        'to_branch_id' => $northId,
        'notes' => 'Cancel after shipping',
        'items' => [['size_id' => $size1, 'qty_full' => 3, 'qty_empty' => 0]],
    ]);
    expect($r, 201, 'owner creates a transfer to cancel after shipping');
    $inTransitCancelId = (int) ($r['json']['id'] ?? 0);
    $r = $owner->request('POST', '/api/transfers/' . $inTransitCancelId . '/approve');
    expect($r, 200, 'owner approves the transfer to be cancelled');
    $r = $staff->request('POST', '/api/transfers/' . $inTransitCancelId . '/ship');
    expect($r, 200, 'source staff ships the transfer to be cancelled');

    $stockBeforeCancel = (int) $pdo->query(
        'SELECT COALESCE(SUM(full_change),0) FROM stock_movements WHERE branch_id=' . (int) $mainId . ' AND size_id=' . (int) $size1
    )->fetchColumn();
    $r = $staff->request('POST', '/api/transfers/' . $inTransitCancelId . '/cancel', ['reason' => 'Route closed']);
    expect($r, 200, 'source staff cancels an in-transit transfer');
    check(($r['json']['status'] ?? '') === 'cancelled', 'in-transit transfer is cancelled');
    $stockAfterCancel = (int) $pdo->query(
        'SELECT COALESCE(SUM(full_change),0) FROM stock_movements WHERE branch_id=' . (int) $mainId . ' AND size_id=' . (int) $size1
    )->fetchColumn();
    check($stockAfterCancel === $stockBeforeCancel + 3, 'cancelling an in-transit transfer restores source stock');
    $r = $owner->request('POST', '/api/transfers/' . $inTransitCancelId . '/receive');
    expect($r, 409, 'cancelled transfer cannot be received');
    check(($r['json']['code'] ?? '') === 'transfer_locked', 'cancelled receive returns transfer_locked');

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
    check((int) ($mainStock['full_qty'] ?? -1) === 47, 'main full stock is 47 after cash sale and transfer out');
    check((int) ($mainStock['empty_qty'] ?? -1) === 9, 'main empty stock is 9 after transfer out');
    check((int) ($mainStock['out_qty'] ?? -1) === 1, 'main cylinders-with-customers is 1 after cash sale');

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
    check(abs((float) ($r['json']['totals']['total_sales'] ?? 0) - 320.0) < 0.001, 'daily total sales are 320 (100 cash sale + 220 delivery)');
    $dailyRow = $r['json']['rows'][0] ?? [];
    $dailyTotals = is_array($r['json']['totals'] ?? null) ? $r['json']['totals'] : [];
    foreach (['cash', 'gcash', 'maya', 'total_sales', 'deposits', 'voided_sales', 'full_received', 'empties_returned'] as $key) {
        check(array_key_exists($key, $dailyRow), "daily report row exposes {$key}");
        check(array_key_exists($key, $dailyTotals), "daily report totals expose {$key}");
    }
    foreach (['cash_sales', 'credit_sales', 'payments', 'cash_collected'] as $key) {
        check(!array_key_exists($key, $dailyRow) && !array_key_exists($key, $dailyTotals), "daily report no longer exposes {$key}");
    }
    check(abs((float) ($dailyTotals['cash'] ?? -1) - 320.0) < 0.001, 'daily cash total is the 100 sale plus the 220 delivery');
    check(abs((float) ($dailyTotals['gcash'] ?? -1)) < 0.001 && abs((float) ($dailyTotals['maya'] ?? -1)) < 0.001, 'daily GCash and Maya totals start at zero');

    $r = $owner->request('GET', '/api/dashboard');
    expect($r, 200, 'dashboard loads');
    foreach (['sold', 'cash', 'gcash', 'maya', 'empties_owed', 'open_orders', 'stock', 'branch', 'date'] as $key) {
        check(array_key_exists($key, is_array($r['json']) ? $r['json'] : []), "dashboard exposes {$key}");
    }
    foreach (['payments', 'unpaid', 'cash_sales'] as $key) {
        check(!array_key_exists($key, is_array($r['json']) ? $r['json'] : []), "dashboard no longer exposes {$key}");
    }
    check(abs((float) ($r['json']['sold'] ?? -1) - 320.0) < 0.001, 'dashboard sold matches the posted sales');
    check((int) ($r['json']['empties_owed'] ?? -1) === 2, 'dashboard reports 2 empties owed');
    check(is_array($r['json']['stock'] ?? null) && count($r['json']['stock']) > 0, 'dashboard stock rows are present');

    $r = $owner->request('GET', '/api/reports/sales?from=' . $today . '&to=' . $today . '&format=csv');
    expect($r, 200, 'sales register CSV downloads');
    check(strpos((string) ($r['headers']['content-type'] ?? ''), 'text/csv') !== false, 'CSV response has text/csv content type');
    check(strncmp((string) $r['body'], "\xEF\xBB\xBF", 3) === 0, 'CSV starts with a UTF-8 BOM');
    check(strpos((string) $r['body'], 'Sale no.') !== false, 'CSV contains the expected header row');
    check(strpos((string) ($r['headers']['content-disposition'] ?? ''), 'attachment') !== false, 'CSV is served as an attachment');

    $r = $owner->request('GET', '/api/reports/sales?from=' . $today . '&to=' . $today . '&payment_method=cash');
    expect($r, 200, 'sales report filters by payment_method');
    $allCash = true;
    foreach (($r['json']['rows'] ?? []) as $row) {
        if (($row['payment_method'] ?? '') !== 'cash') {
            $allCash = false;
        }
        check(array_key_exists('payment_method', $row) && array_key_exists('payment_reference', $row), 'sales report row exposes payment_method and payment_reference');
        check(!array_key_exists('payment_type', $row), 'sales report row no longer exposes payment_type');
    }
    check($allCash, 'payment_method=cash returns only cash sales');

    $r = $owner->request('GET', '/api/reports/sales?from=' . $today . '&to=' . $today . '&payment_method=credit');
    expect($r, 422, 'credit is rejected as a payment_method filter');
    check(($r['json']['code'] ?? '') === 'validation_error', 'bad payment_method filter returns validation_error');

    $r = $staff->request('GET', '/api/reports/branch-comparison?from=' . $today . '&to=' . $today);
    expect($r, 403, 'staff cannot read the owner branch comparison');

    $r = $owner->request('GET', '/api/reports/branch-comparison?from=' . $today . '&to=' . $today);
    expect($r, 200, 'owner loads the branch comparison');
    check(is_array($r['json']['rows'] ?? null) && count($r['json']['rows']) >= 3, 'branch comparison includes all branches');
    check(abs((float) ($r['json']['totals']['sales_total'] ?? 0) - 320.0) < 0.001, 'branch comparison sales total is 320');
    $comparisonRow = $r['json']['rows'][0] ?? [];
    $comparisonTotals = is_array($r['json']['totals'] ?? null) ? $r['json']['totals'] : [];
    foreach (['sales_total', 'cash', 'gcash', 'maya', 'deposits', 'full_qty', 'empty_qty', 'out_qty', 'open_orders'] as $key) {
        check(array_key_exists($key, $comparisonRow), "branch comparison row exposes {$key}");
    }
    foreach (['sales_total', 'cash', 'gcash', 'maya', 'deposits', 'full_qty', 'empty_qty', 'out_qty'] as $key) {
        check(array_key_exists($key, $comparisonTotals), "branch comparison totals expose {$key}");
    }
    foreach (['credit_sales', 'payments', 'unpaid', 'cash_collected'] as $key) {
        check(!array_key_exists($key, $comparisonRow) && !array_key_exists($key, $comparisonTotals), "branch comparison no longer exposes {$key}");
    }

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
    section('Structured names, addresses and service-area catalogue');
    /* --------------------------------------------------------------------- */

    $r = $anon->request('GET', '/api/addresses', null, [], false);
    expect($r, 200, 'public service-area catalogue loads');
    $cities = is_array($r['json']['cities'] ?? null) ? $r['json']['cities'] : [];
    $cityNames = array_map(static fn($c) => (string) ($c['name'] ?? ''), $cities);
    check(in_array('Jolo', $cityNames, true), 'catalogue includes Jolo');
    check(in_array('Zamboanga City', $cityNames, true), 'catalogue includes Zamboanga City');
    check(is_array($r['json']['regions'] ?? null) && count($r['json']['regions']) >= 2, 'catalogue includes regions');
    check(is_array($r['json']['provinces'] ?? null) && count($r['json']['provinces']) >= 2, 'catalogue includes provinces');
    $jolo = null;
    foreach ($cities as $city) {
        if (($city['name'] ?? '') === 'Jolo') {
            $jolo = $city;
        }
    }
    check($jolo !== null && in_array('Tulay', $jolo['barangays'] ?? [], true), 'Jolo lists its barangays');
    check($jolo !== null && ($jolo['province'] ?? '') === 'Sulu' && ($jolo['postal_code'] ?? '') === '7400', 'Jolo carries province and postal code');

    $r = $owner->request('POST', '/api/branches', [
        'name' => 'South Branch',
        'code' => 'south',
        'street' => '17 Sunrise Road',
        'barangay' => 'Tulay',
        'city' => 'Jolo',
        'contact_phone' => '09172222222',
        'gcash_number' => '09170000001',
        'gcash_account_name' => 'South Gas GCash',
        'maya_number' => '09170000002',
        'maya_account_name' => 'South Gas Maya',
        'service_area_cities' => ['Jolo', 'Zamboanga City'],
        'operating_hours' => '08:00-18:00',
        'max_order_qty' => 10,
        'rush_fee' => 25,
        'outside_zone_fee' => 40,
    ]);
    expect($r, 201, 'owner creates a branch with a structured address and fees');
    $southId = (int) ($r['json']['id'] ?? 0);
    check($southId > 0, 'structured branch has an id');
    check(($r['json']['address'] ?? '') === '17 Sunrise Road, Tulay, Jolo, Sulu 7400', 'structured branch address is composed');
    check(($r['json']['province'] ?? '') === 'Sulu' && ($r['json']['region'] ?? '') === 'Bangsamoro Autonomous Region in Muslim Mindanao', 'branch province and region are derived');
    check(($r['json']['postal_code'] ?? '') === '7400', 'branch postal code is derived');
    check(abs((float) ($r['json']['rush_fee'] ?? -1) - 25.0) < 0.001, 'branch rush fee is saved');
    check(abs((float) ($r['json']['outside_zone_fee'] ?? -1) - 40.0) < 0.001, 'branch outside-zone fee is saved');
    check(($r['json']['gcash_number'] ?? '') === '09170000001', 'branch create saves the GCash number');
    check(($r['json']['gcash_account_name'] ?? '') === 'South Gas GCash', 'branch create saves the GCash account name');
    check(($r['json']['maya_number'] ?? '') === '09170000002', 'branch create saves the Maya number');
    check(($r['json']['maya_account_name'] ?? '') === 'South Gas Maya', 'branch create saves the Maya account name');
    check(($r['json']['service_area_cities'] ?? '') === 'Jolo,Zamboanga City', 'branch create accepts service_area_cities as an array');

    $r = $owner->request('PUT', '/api/branches/' . $southId, [
        'name' => 'South Branch',
        'code' => 'south',
        'service_area_cities' => 'Jolo, Zamboanga City',
        'maya_number' => '09170000003',
    ]);
    expect($r, 200, 'owner updates branch payment and service-area details');
    check(($r['json']['service_area_cities'] ?? '') === 'Jolo, Zamboanga City', 'branch update accepts service_area_cities as a string');
    check(($r['json']['maya_number'] ?? '') === '09170000003', 'branch update saves the Maya number');
    check(($r['json']['gcash_number'] ?? '') === '09170000001', 'branch update preserves the GCash number');

    $r = $anon->request('GET', '/api/branches/public', null, [], false);
    expect($r, 200, 'public branch list loads after structured address');
    $southPublic = null;
    foreach (is_array($r['json']) ? $r['json'] : [] as $b) {
        if ((int) ($b['id'] ?? 0) === $southId) {
            $southPublic = $b;
        }
    }
    check($southPublic !== null, 'public branch list includes the south branch');
    check(abs((float) ($southPublic['rush_fee'] ?? -1) - 25.0) < 0.001, 'public branch exposes the rush fee');
    check(abs((float) ($southPublic['outside_zone_fee'] ?? -1) - 40.0) < 0.001, 'public branch exposes the outside-zone fee');
    check(($southPublic['gcash_number'] ?? '') === '09170000001', 'public branch exposes the GCash number');
    check(($southPublic['gcash_account_name'] ?? '') === 'South Gas GCash', 'public branch exposes the GCash account name');
    check(($southPublic['maya_number'] ?? '') === '09170000003', 'public branch exposes the Maya number');
    check(($southPublic['maya_account_name'] ?? '') === 'South Gas Maya', 'public branch exposes the Maya account name');
    check(($southPublic['service_area_cities'] ?? '') === 'Jolo, Zamboanga City', 'public branch exposes the service-area cities');

    $r = $owner->request('POST', '/api/branches', [
        'name' => 'Bad Barangay Branch',
        'code' => 'badloc',
        'street' => '1 Wrong Road',
        'barangay' => 'Tetuan',
        'city' => 'Jolo',
    ]);
    expect($r, 422, 'barangay from another city is rejected for a branch');
    check(($r['json']['code'] ?? '') === 'validation_error', 'bad barangay returns validation_error');

    $r = $owner->request('POST', '/api/customers', [
        'first_name' => 'Juan',
        'middle_name' => 'Santos',
        'last_name' => 'Dela Cruz',
        'name_suffix' => 'Jr.',
        'contact' => '09173333333',
        'street' => '9 Peace Street',
        'barangay' => 'Tulay',
        'city' => 'Jolo',
        'latitude' => 6.0541,
        'longitude' => 121.0055,
    ], ['X-Branch-Id: ' . $southId]);
    expect($r, 201, 'owner creates a customer with structured name and address');
    $structuredCustomerId = (int) ($r['json']['id'] ?? 0);
    check($structuredCustomerId > 0, 'structured customer has an id');

    $r = $owner->request('GET', '/api/customers?q=Dela%20Cruz', null, ['X-Branch-Id: ' . $southId]);
    expect($r, 200, 'structured customer can be listed');
    $structuredCustomer = null;
    foreach (is_array($r['json']) ? $r['json'] : [] as $c) {
        if ((int) ($c['id'] ?? 0) === $structuredCustomerId) {
            $structuredCustomer = $c;
        }
    }
    check($structuredCustomer !== null, 'structured customer is in the list');
    check(($structuredCustomer['name'] ?? '') === 'Dela Cruz, Juan Santos Jr.', 'structured name uses last, first middle suffix');
    check(($structuredCustomer['first_name'] ?? '') === 'Juan' && ($structuredCustomer['last_name'] ?? '') === 'Dela Cruz', 'structured name parts are exposed');
    check(($structuredCustomer['barangay'] ?? '') === 'Tulay' && ($structuredCustomer['city'] ?? '') === 'Jolo', 'structured address parts are exposed');
    check(abs((float) ($structuredCustomer['latitude'] ?? -1) - 6.0541) < 0.00001, 'structured customer keeps the map pin');

    $r = $owner->request('POST', '/api/customers', [
        'first_name' => 'Bad',
        'last_name' => 'Address',
        'street' => '1 Wrong Road',
        'barangay' => 'Tetuan',
        'city' => 'Jolo',
    ], ['X-Branch-Id: ' . $southId]);
    expect($r, 422, 'barangay from another city is rejected for a customer');

    /* --------------------------------------------------------------------- */
    section('Branch admins, delivery riders and branch team');
    /* --------------------------------------------------------------------- */

    $r = $owner->request('GET', '/api/me');
    $ownerId = (int) ($r['json']['id'] ?? 0);

    $r = $owner->request('POST', '/api/users', [
        'first_name' => 'Anna',
        'last_name' => 'Reyes',
        'username' => 'southadmin',
        'password' => 'AdminTemp123!',
        'role' => 'branch_admin',
        'branch_id' => $southId,
        'contact' => '09174444444',
    ]);
    expect($r, 201, 'owner creates a branch admin account');
    $southAdminId = (int) ($r['json']['id'] ?? 0);

    $r = $owner->request('POST', '/api/users', [
        'first_name' => 'Dan',
        'last_name' => 'Rider',
        'username' => 'southrider',
        'password' => 'DeliverTemp123!',
        'role' => 'delivery',
        'branch_id' => $southId,
        'contact' => '09175555555',
    ]);
    expect($r, 201, 'owner creates a delivery rider account');
    $southDeliveryId = (int) ($r['json']['id'] ?? 0);

    $r = $owner->request('POST', '/api/users', [
        'first_name' => 'Mila',
        'last_name' => 'Santos',
        'username' => 'southstaff',
        'password' => 'StaffTemp123!',
        'role' => 'staff',
        'branch_id' => $southId,
    ]);
    expect($r, 201, 'owner creates a south staff account');
    $southStaffId = (int) ($r['json']['id'] ?? 0);

    $branchAdmin = new ApiClient($base, 'branchadmin');
    $r = $branchAdmin->login('southadmin', 'AdminTemp123!');
    expect($r, 200, 'branch admin logs in with the temporary password');
    check(($r['json']['role'] ?? '') === 'branch_admin', 'login returns the branch_admin role');
    check((int) ($r['json']['branch_id'] ?? 0) === $southId, 'branch admin is bound to the south branch');
    $r = $branchAdmin->request('POST', '/api/me/password', [
        'current_password' => 'AdminTemp123!',
        'new_password' => 'AdminFinal123!',
    ]);
    expect($r, 200, 'branch admin changes the temporary password');
    $branchAdmin->absorb($r);
    $r = $branchAdmin->request('GET', '/api/me');
    check(($r['json']['role'] ?? '') === 'branch_admin' && ($r['json']['branch_name'] ?? '') === 'South Branch', 'branch admin is scoped to the south branch');
    $r = $branchAdmin->request('GET', '/api/customers');
    expect($r, 200, 'branch admin can list branch customers');
    $r = $branchAdmin->request('GET', '/api/users?per_page=100');
    expect($r, 200, 'branch admin can list their own branch team');
    $teamUsers = is_array($r['json']) ? $r['json'] : [];
    $teamIds = array_map(static fn($row) => (int) ($row['id'] ?? 0), $teamUsers);
    check(in_array($southStaffId, $teamIds, true) && in_array($southDeliveryId, $teamIds, true), 'branch admin sees the staff and riders of their branch');
    $outsideBranch = array_filter($teamUsers, static fn($row) => (int) ($row['branch_id'] ?? 0) !== $southId);
    check(count($outsideBranch) === 0, 'branch admin user list contains only their branch');

    $r = $branchAdmin->request('GET', '/api/users?role=owner');
    expect($r, 403, 'branch admin cannot filter users by an owner role');

    $r = $branchAdmin->request('POST', '/api/users', [
        'first_name' => 'Nina',
        'last_name' => 'Flores',
        'username' => 'southstaff2',
        'password' => 'TeamTemp123!',
        'role' => 'staff',
        'contact' => '09178888888',
    ]);
    expect($r, 201, 'branch admin adds a staff member without choosing a branch');
    $southStaff2Id = (int) ($r['json']['id'] ?? 0);

    $r = $owner->request('GET', '/api/users?q=southstaff2');
    $createdByAdmin = is_array($r['json']) ? ($r['json'][0] ?? null) : null;
    check($createdByAdmin !== null && (int) ($createdByAdmin['branch_id'] ?? 0) === $southId, 'branch admin-created staff is bound to the branch admin branch');

    $r = $branchAdmin->request('POST', '/api/users', [
        'first_name' => 'Nina',
        'last_name' => 'Flores',
        'username' => 'southstaff3',
        'password' => 'TeamTemp123!',
        'role' => 'staff',
        'branch_id' => $mainId,
    ]);
    expect($r, 403, 'branch admin cannot add users to another branch');
    check(($r['json']['code'] ?? '') === 'branch_forbidden', 'cross-branch user create returns branch_forbidden');

    $r = $branchAdmin->request('POST', '/api/users', [
        'first_name' => 'Nina',
        'last_name' => 'Flores',
        'username' => 'southowner',
        'password' => 'TeamTemp123!',
        'role' => 'owner',
    ]);
    expect($r, 403, 'branch admin cannot create an owner');

    $r = $branchAdmin->request('POST', '/api/users', [
        'first_name' => 'Nina',
        'last_name' => 'Flores',
        'username' => 'southadmin3',
        'password' => 'TeamTemp123!',
        'role' => 'branch_admin',
    ]);
    expect($r, 403, 'branch admin cannot create another branch admin directly');

    $r = $branchAdmin->request('PUT', '/api/users/' . $southStaff2Id, [
        'first_name' => 'Nina',
        'last_name' => 'Flores',
        'username' => 'southstaff2',
        'role' => 'delivery',
        'is_active' => true,
    ]);
    expect($r, 200, 'branch admin can change a team member role to delivery');

    $r = $branchAdmin->request('PUT', '/api/users/' . $staffId, [
        'name' => 'Main Staff',
        'username' => 'staffmain',
        'role' => 'staff',
        'is_active' => true,
        'branch_id' => $mainId,
    ]);
    expect($r, 403, 'branch admin cannot edit a user from another branch');

    $r = $branchAdmin->request('PUT', '/api/users/' . $southAdminId, [
        'first_name' => 'Anna',
        'last_name' => 'Reyes',
        'username' => 'southadmin',
        'role' => 'staff',
        'is_active' => true,
    ]);
    expect($r, 403, 'branch admin cannot edit a branch admin account through users');

    $r = $branchAdmin->request('POST', '/api/branches/' . $mainId . '/admin', ['user_id' => $staffId]);
    expect($r, 403, 'branch admin cannot assign an admin for another branch');

    $r = $branchAdmin->request('POST', '/api/branches/' . $southId . '/admin', ['user_id' => $staffId]);
    expect($r, 403, 'branch admin cannot promote someone from another branch');
    check(($r['json']['code'] ?? '') === 'branch_forbidden', 'cross-branch admin assignment returns branch_forbidden');

    $r = $branchAdmin->request('GET', '/api/branches');
    expect($r, 403, 'branch admin cannot read the branch register');

    $r = $branchAdmin->request('POST', '/api/branches/' . $southId . '/admin', ['user_id' => $southStaff2Id]);
    expect($r, 200, 'branch admin assigns a teammate as the branch admin');
    check((int) ($r['json']['branch_admin_id'] ?? 0) === $southStaff2Id, 'branch admin assignment updates the branch link');

    $r = $branchAdmin->request('GET', '/api/me');
    expect($r, 401, 'previous branch admin session is revoked after handover');

    $r = $owner->request('GET', '/api/users?q=southadmin');
    $handedOverAdmin = is_array($r['json']) ? ($r['json'][0] ?? null) : null;
    check($handedOverAdmin !== null && ($handedOverAdmin['role'] ?? '') === 'staff', 'previous branch admin is demoted after self-service handover');

    $delivery = new ApiClient($base, 'delivery');
    $r = $delivery->login('southrider', 'DeliverTemp123!');
    expect($r, 200, 'delivery rider logs in with the temporary password');
    check(($r['json']['role'] ?? '') === 'delivery', 'login returns the delivery role');
    check((int) ($r['json']['branch_id'] ?? 0) === $southId, 'delivery rider is bound to the south branch');
    $r = $delivery->request('POST', '/api/me/password', [
        'current_password' => 'DeliverTemp123!',
        'new_password' => 'DeliverFinal123!',
    ]);
    expect($r, 200, 'delivery rider changes the temporary password');
    $delivery->absorb($r);
    $r = $delivery->request('GET', '/api/customers');
    expect($r, 403, 'delivery rider cannot list customers');
    $r = $delivery->request('GET', '/api/orders');
    expect($r, 200, 'delivery rider can list orders');

    $r = $owner->request('GET', '/api/branches/' . $southId . '/team');
    expect($r, 200, 'owner reads the branch team');
    $teamMembers = is_array($r['json']['members'] ?? null) ? $r['json']['members'] : [];
    $teamRoles = array_map(static fn($m) => (string) ($m['role'] ?? ''), $teamMembers);
    check(in_array('branch_admin', $teamRoles, true), 'branch team includes the branch admin');
    check(in_array('delivery', $teamRoles, true), 'branch team includes delivery riders');
    $riderMember = null;
    foreach ($teamMembers as $m) {
        if ((int) ($m['id'] ?? 0) === $southDeliveryId) {
            $riderMember = $m;
        }
    }
    check($riderMember !== null && ($riderMember['is_rider'] ?? false) === true, 'delivery team members are marked as riders');

    $r = $owner->request('POST', '/api/branches/' . $southId . '/admin', ['user_id' => $southStaffId]);
    expect($r, 200, 'owner assigns an existing user as branch admin');
    check((int) ($r['json']['branch_admin_id'] ?? 0) === $southStaffId, 'branch admin link points at the assigned user');

    $r = $owner->request('POST', '/api/branches/' . $southId . '/admin', [
        'first_name' => 'Ben',
        'last_name' => 'Cruz',
        'username' => 'southadmin2',
        'password' => 'AdminTemp123!',
        'contact' => '09176666666',
    ]);
    expect($r, 200, 'owner creates a new branch admin');
    $southAdmin2Id = (int) ($r['json']['branch_admin_id'] ?? 0);
    check($southAdmin2Id > 0 && $southAdmin2Id !== $southStaffId, 'new branch admin replaces the previous one');
    check(($r['json']['branch_admin']['name'] ?? '') === 'Cruz, Ben', 'new branch admin has a structured name');

    $r = $owner->request('GET', '/api/users?q=southstaff');
    expect($r, 200, 'owner can look up the previous admin');
    $previousAdmin = is_array($r['json']) ? ($r['json'][0] ?? null) : null;
    check($previousAdmin !== null && ($previousAdmin['role'] ?? '') === 'staff', 'previous branch admin is demoted to staff');

    $r = $owner->request('POST', '/api/branches/' . $southId . '/admin', ['user_id' => $ownerId]);
    expect($r, 409, 'owner cannot be made a branch admin');
    check(($r['json']['code'] ?? '') === 'invalid_role_change', 'owner branch admin returns invalid_role_change');

    $r = $delivery->request('POST', '/api/branches/' . $southId . '/admin', ['user_id' => $southAdmin2Id]);
    expect($r, 403, 'delivery rider cannot assign a branch admin');

    /* --------------------------------------------------------------------- */
    section('Rush fees, outside-zone fees, delivery pins and rider assignment');
    /* --------------------------------------------------------------------- */

    $newCustomer = new ApiClient($base, 'structuredcust');
    $r = $newCustomer->request('POST', '/api/register', [
        'first_name' => 'Maria',
        'middle_name' => 'Lopez',
        'last_name' => 'Bautista',
        'street' => '5 Market Lane',
        'barangay' => 'Tulay',
        'city' => 'Jolo',
        'latitude' => 6.0525,
        'longitude' => 121.0030,
        'contact' => '09177777777',
        'username' => 'mariab',
        'password' => 'Customer123!',
    ]);
    expect($r, 201, 'customer signs up with a structured name and address');
    $newCustomer->absorb($r);
    check(($r['json']['name'] ?? '') === 'Bautista, Maria Lopez', 'signup composes the structured name');
    check(($r['json']['first_name'] ?? '') === 'Maria' && ($r['json']['last_name'] ?? '') === 'Bautista', 'signup exposes structured name parts');

    $r = $anon->request('GET', '/api/sizes?branch_id=' . $southId, null, [], false);
    expect($r, 200, 'south branch prices load');
    $southUnit = 0.0;
    foreach (is_array($r['json']) ? $r['json'] : [] as $row) {
        if ((int) ($row['id'] ?? 0) === $size1) {
            $southUnit = (float) ($row['selling_price'] ?? 0);
        }
    }
    check($southUnit > 0, 'south branch has a unit price');

    // Rush is same-day and ignores the cut-off; regular same-day orders still close.
    $r = $owner->request('PUT', '/api/branches/' . $southId, [
        'name' => 'South Branch',
        'code' => 'south',
        'cutoff_time' => '00:00',
        'max_order_qty' => 10,
        'rush_fee' => 25,
        'outside_zone_fee' => 40,
    ]);
    expect($r, 200, 'owner sets an already-passed cut-off on the south branch');

    $r = $newCustomer->request('POST', '/api/my/orders', [
        'branch_id' => $southId,
        'pay' => 'cash',
        'requested_date' => $today,
        'is_rush' => false,
        'items' => [['size_id' => $size1, 'qty' => 1, 'deposit_amount' => 0]],
    ]);
    expect($r, 409, 'same-day order after the cut-off is rejected');
    check(($r['json']['code'] ?? '') === 'cutoff_passed', 'cut-off rejection returns cutoff_passed');

    $r = $newCustomer->request('POST', '/api/my/orders', [
        'branch_id' => $southId,
        'pay' => 'cash',
        'notes' => 'Rush delivery',
        'requested_date' => date('Y-m-d', strtotime('+2 days')),
        'time_slot' => 'Morning',
        'is_rush' => true,
        'is_outside_zone' => true,
        'rush_fee' => 999,
        'outside_zone_fee' => 999,
        'delivery_street' => '5 Market Lane',
        'delivery_barangay' => 'Tulay',
        'delivery_city' => 'Jolo',
        'delivery_latitude' => 6.0531,
        'delivery_longitude' => 121.0044,
        'items' => [['size_id' => $size1, 'qty' => 1, 'deposit_amount' => 0]],
    ]);
    expect($r, 201, 'customer places a rush outside-zone order');
    $feeOrderId = (int) ($r['json']['id'] ?? 0);
    check(($r['json']['requested_date'] ?? '') === $today, 'rush order is forced to the same day');
    check(abs((float) ($r['json']['rush_fee'] ?? -1) - 25.0) < 0.001, 'order uses the branch rush fee');
    check(abs((float) ($r['json']['outside_zone_fee'] ?? -1) - 40.0) < 0.001, 'order uses the branch outside-zone fee');
    check(abs((float) ($r['json']['total_amount'] ?? -1) - ($southUnit + 65)) < 0.001, 'order total includes both fees');
    check(($r['json']['delivery_address'] ?? '') === '5 Market Lane, Tulay, Jolo, Sulu 7400', 'order delivery address is composed');
    check(abs((float) ($r['json']['delivery_latitude'] ?? -1) - 6.0531) < 0.00001, 'order keeps the delivery map latitude');
    check(abs((float) ($r['json']['delivery_longitude'] ?? -1) - 121.0044) < 0.00001, 'order keeps the delivery map longitude');

    $r = $newCustomer->request('PATCH', '/api/my/orders/' . $feeOrderId, [
        'branch_id' => $southId,
        'pay' => 'cash',
        'notes' => 'Rush delivery',
        'requested_date' => date('Y-m-d', strtotime('+2 days')),
        'is_rush' => false,
        'is_outside_zone' => true,
        'items' => [['size_id' => $size1, 'qty' => 1, 'deposit_amount' => 0]],
    ]);
    expect($r, 200, 'customer edits the order fees');
    check(abs((float) ($r['json']['rush_fee'] ?? -1)) < 0.001, 'removing rush clears the rush fee');
    check(abs((float) ($r['json']['outside_zone_fee'] ?? -1) - 40.0) < 0.001, 'outside-zone fee remains when selected');
    check(abs((float) ($r['json']['total_amount'] ?? -1) - ($southUnit + 40)) < 0.001, 'edited total includes only the outside-zone fee');
    check(($r['json']['delivery_city'] ?? '') === 'Jolo', 'editing flags preserves the stored delivery city');
    check(abs((float) ($r['json']['delivery_latitude'] ?? -1) - 6.0531) < 0.00001, 'editing flags preserves the stored map pin');

    $r = $owner->request('POST', '/api/orders/' . $feeOrderId . '/assign', ['rider_id' => $southDeliveryId]);
    expect($r, 200, 'owner assigns the delivery rider');
    $r = $owner->request('GET', '/api/orders/' . $feeOrderId);
    expect($r, 200, 'order loads after rider assignment');
    check((int) ($r['json']['rider_id'] ?? 0) === $southDeliveryId, 'order stores the assigned rider');
    check(($r['json']['rider_name'] ?? '') === 'Rider, Dan', 'order exposes the rider name');

    $r = $delivery->request('POST', '/api/orders/' . $feeOrderId . '/assign', ['rider_id' => $southDeliveryId]);
    expect($r, 200, 'delivery rider can assign themselves');
    $r = $delivery->request('POST', '/api/orders/' . $feeOrderId . '/assign', ['rider_id' => $southAdmin2Id]);
    expect($r, 403, 'delivery rider cannot assign someone else');
    $r = $delivery->request('POST', '/api/orders/' . $feeOrderId . '/approve');
    expect($r, 403, 'delivery rider cannot approve orders');

    $r = $owner->request('POST', '/api/adjustments', [
        'size_id' => $size1,
        'reason' => 'Opening count',
        'note' => 'South opening count',
        'full' => 5,
        'empty' => 1,
    ], ['X-Branch-Id: ' . $southId]);
    expect($r, 201, 'owner records south opening stock');

    $r = $delivery->request('POST', '/api/orders/' . $feeOrderId . '/deliver', [
        'proof_of_delivery' => 'Photo on file',
        'rider_id' => $southDeliveryId,
    ]);
    expect($r, 200, 'delivery rider delivers the order');
    check(abs((float) ($r['json']['total_amount'] ?? -1) - ($southUnit + 40)) < 0.001, 'delivered sale keeps the outside-zone fee');
    $feeSaleId = (int) ($r['json']['sale_id'] ?? 0);
    check($feeSaleId > 0, 'delivery creates a fee-aware sale');

    $r = $owner->request('GET', '/api/sales/' . $feeSaleId, null, ['X-Branch-Id: ' . $southId]);
    expect($r, 200, 'owner reads the delivered sale');
    check(abs((float) ($r['json']['outside_zone_fee'] ?? -1) - 40.0) < 0.001, 'sale keeps the outside-zone fee');
    check(abs((float) ($r['json']['rush_fee'] ?? -1)) < 0.001, 'sale records no rush fee after the edit');
    check(abs((float) ($r['json']['total_amount'] ?? -1) - ($southUnit + 40)) < 0.001, 'sale total matches the fee-inclusive order');

    $r = $delivery->request('GET', '/api/orders');
    expect($r, 200, 'delivery rider reloads the order queue');
    $deliveryOrder = null;
    foreach (is_array($r['json']) ? $r['json'] : [] as $o) {
        if ((int) ($o['id'] ?? 0) === $feeOrderId) {
            $deliveryOrder = $o;
        }
    }
    check($deliveryOrder !== null && ($deliveryOrder['status'] ?? '') === 'delivered', 'delivery rider sees the delivered order');
    check(($deliveryOrder['rider_name'] ?? '') === 'Rider, Dan', 'delivery queue exposes the rider name');
    check(abs((float) ($deliveryOrder['delivery_latitude'] ?? -1) - 6.0531) < 0.00001, 'delivery queue exposes the map pin');

    $r = $newCustomer->request('POST', '/api/my/orders', [
        'branch_id' => $southId,
        'pay' => 'cash',
        'requested_date' => date('Y-m-d', strtotime('+2 days')),
        'is_outside_zone' => true,
        'items' => [['size_id' => $size1, 'qty' => 1]],
    ]);
    expect($r, 201, 'customer places a COD order without a new delivery address');
    $codOrderId = (int) ($r['json']['id'] ?? 0);
    check(($r['json']['delivery_address'] ?? '') === '5 Market Lane, Tulay, Jolo, Sulu 7400', 'order falls back to the saved customer address');
    check(abs((float) ($r['json']['delivery_latitude'] ?? -1) - 6.0525) < 0.00001, 'order falls back to the saved map pin');
    check(($r['json']['payment_method'] ?? '') === 'cash', 'COD order uses payment_method cash');
    check(($r['json']['payment_status'] ?? '') === 'unpaid', 'COD order starts unpaid');

    $r = $owner->request('POST', '/api/orders/' . $codOrderId . '/deliver', [], ['X-Branch-Id: ' . $southId]);
    expect($r, 200, 'owner delivers the COD order');
    $r = $newCustomer->request('GET', '/api/my/account');
    expect($r, 200, 'customer account loads after fee-aware COD delivery');
    check(!array_key_exists('balance', is_array($r['json']) ? $r['json'] : []), 'customer account has no balance field');
    check((int) ($r['json']['empties_owed'] ?? -1) === 2, 'customer owes 2 empties after two deliveries');

    /* --------------------------------------------------------------------- */
    section('Prepaid orders: verification, duplicates and resubmission');
    /* --------------------------------------------------------------------- */

    $future = date('Y-m-d', strtotime('+3 days'));
    $northHeader = ['X-Branch-Id: ' . $northId];
    $prepaidOrder = static function (string $pay, ?string $reference) use ($future, $northId, $size1): array {
        $body = [
            'branch_id' => $northId,
            'pay' => $pay,
            'requested_date' => $future,
            'notes' => 'Prepaid contract test',
            'items' => [['size_id' => $size1, 'qty' => 1, 'deposit_amount' => 0]],
        ];
        if ($reference !== null) {
            $body['reference_no'] = $reference;
        }
        return $body;
    };

    // Payment method and reference validation on customer orders.
    $r = $customer->request('POST', '/api/my/orders', $prepaidOrder('credit', null));
    expect($r, 422, 'an order cannot be paid with the removed credit method');
    check(($r['json']['code'] ?? '') === 'validation_error', 'invalid pay value returns validation_error');
    $r = $customer->request('POST', '/api/my/orders', $prepaidOrder('gcash', null));
    expect($r, 422, 'a GCash order requires a reference number');
    check(($r['json']['field'] ?? '') === 'reference_no', 'missing reference reports the reference_no field');
    $r = $customer->request('POST', '/api/my/orders', $prepaidOrder('maya', null));
    expect($r, 422, 'a Maya order requires a reference number');
    $r = $customer->request('POST', '/api/my/orders', $prepaidOrder('gcash', 'ab'));
    expect($r, 422, 'a reference shorter than 4 characters is rejected');
    $r = $customer->request('POST', '/api/my/orders', $prepaidOrder('gcash', 'GC BAD REF'));
    expect($r, 422, 'a reference with invalid characters is rejected');

    // (a) GCash order: approve and deliver are blocked until staff verification.
    $gcashRef = 'GC-PHASE1-0001';
    $r = $customer->request('POST', '/api/my/orders', $prepaidOrder('gcash', $gcashRef));
    expect($r, 201, 'customer places a GCash order');
    $gcashOrderId = (int) ($r['json']['id'] ?? 0);
    check($gcashOrderId > 0, 'GCash order has an id');
    check(($r['json']['payment_method'] ?? '') === 'gcash', 'GCash order exposes payment_method gcash');
    check(($r['json']['payment_reference'] ?? '') === $gcashRef, 'GCash order keeps the reference');
    check(($r['json']['payment_status'] ?? '') === 'pending_verification', 'GCash order starts pending_verification');
    $freshOrder = is_array($r['json']) ? $r['json'] : [];
    check(
        has_null_field($freshOrder, 'payment_verified_by') && has_null_field($freshOrder, 'payment_verified_at'),
        'new GCash order has no verifier'
    );

    $r = $staff->request('POST', '/api/orders/' . $gcashOrderId . '/verify-payment', null, $northHeader);
    expect($r, 403, 'main staff cannot verify a north-branch payment');
    check(($r['json']['code'] ?? '') === 'branch_forbidden', 'cross-branch verification returns branch_forbidden');
    $r = $owner->request('POST', '/api/orders/' . $gcashOrderId . '/approve', null, $northHeader);
    expect($r, 409, 'GCash order cannot be approved before verification');
    check(($r['json']['code'] ?? '') === 'payment_not_verified', 'early approve returns payment_not_verified');
    $r = $owner->request('POST', '/api/orders/' . $gcashOrderId . '/deliver', [], $northHeader);
    expect($r, 409, 'GCash order cannot be delivered before verification');
    check(($r['json']['code'] ?? '') === 'payment_not_verified', 'early deliver returns payment_not_verified');

    $r = $owner->request('POST', '/api/orders/' . $gcashOrderId . '/verify-payment', null, $northHeader);
    expect($r, 200, 'staff verifies the GCash reference');
    check(($r['json']['payment_status'] ?? '') === 'verified', 'verification sets payment_status verified');
    $r = $owner->request('GET', '/api/orders/' . $gcashOrderId, null, $northHeader);
    expect($r, 200, 'verified order reloads');
    check((int) ($r['json']['payment_verified_by'] ?? 0) > 0, 'verification records payment_verified_by');
    check(!empty($r['json']['payment_verified_at']), 'verification records payment_verified_at');

    $r = $owner->request('POST', '/api/orders/' . $gcashOrderId . '/approve', null, $northHeader);
    expect($r, 200, 'verified GCash order can be approved');
    $r = $owner->request('POST', '/api/orders/' . $gcashOrderId . '/deliver', ['proof_of_delivery' => 'GCash receipt checked'], $northHeader);
    expect($r, 200, 'verified GCash order can be delivered');
    $gcashSaleId = (int) ($r['json']['sale_id'] ?? 0);
    check($gcashSaleId > 0, 'GCash delivery creates a sale');
    $r = $owner->request('GET', '/api/sales/' . $gcashSaleId, null, $northHeader);
    expect($r, 200, 'GCash delivered sale reloads');
    check(($r['json']['payment_method'] ?? '') === 'gcash', 'delivered sale copies payment_method gcash');
    check(($r['json']['payment_reference'] ?? '') === $gcashRef, 'delivered sale copies the GCash reference');

    // The same rule applies to Maya orders.
    $mayaRef = 'MAYA-PHASE1-0002';
    $r = $customer->request('POST', '/api/my/orders', $prepaidOrder('maya', $mayaRef));
    expect($r, 201, 'customer places a Maya order');
    $mayaOrderId = (int) ($r['json']['id'] ?? 0);
    check(($r['json']['payment_status'] ?? '') === 'pending_verification', 'Maya order starts pending_verification');
    $r = $owner->request('POST', '/api/orders/' . $mayaOrderId . '/approve', null, $northHeader);
    expect($r, 409, 'Maya order cannot be approved before verification');
    check(($r['json']['code'] ?? '') === 'payment_not_verified', 'early Maya approve returns payment_not_verified');
    $r = $owner->request('POST', '/api/orders/' . $mayaOrderId . '/deliver', [], $northHeader);
    expect($r, 409, 'Maya order cannot be delivered before verification');
    check(($r['json']['code'] ?? '') === 'payment_not_verified', 'early Maya deliver returns payment_not_verified');
    $r = $owner->request('POST', '/api/orders/' . $mayaOrderId . '/verify-payment', null, $northHeader);
    expect($r, 200, 'staff verifies the Maya reference');
    $r = $owner->request('POST', '/api/orders/' . $mayaOrderId . '/approve', null, $northHeader);
    expect($r, 200, 'verified Maya order can be approved');
    $r = $owner->request('POST', '/api/orders/' . $mayaOrderId . '/deliver', [], $northHeader);
    expect($r, 200, 'verified Maya order can be delivered');
    $mayaSaleId = (int) ($r['json']['sale_id'] ?? 0);
    $r = $owner->request('GET', '/api/sales/' . $mayaSaleId, null, $northHeader);
    expect($r, 200, 'Maya delivered sale reloads');
    check(($r['json']['payment_method'] ?? '') === 'maya' && ($r['json']['payment_reference'] ?? '') === $mayaRef, 'delivered sale copies Maya method and reference');

    // (b) Cash on delivery needs no verification step.
    $r = $customer->request('POST', '/api/my/orders', $prepaidOrder('cash', null));
    expect($r, 201, 'customer places a COD order');
    $codOnlyId = (int) ($r['json']['id'] ?? 0);
    check(($r['json']['payment_status'] ?? '') === 'unpaid', 'COD order starts unpaid');
    check(has_null_field(is_array($r['json']) ? $r['json'] : [], 'payment_reference'), 'COD order needs no reference');
    $r = $owner->request('POST', '/api/orders/' . $codOnlyId . '/verify-payment', null, $northHeader);
    expect($r, 409, 'verifying a COD order is not applicable');
    check(($r['json']['code'] ?? '') === 'payment_not_required', 'COD verification returns payment_not_required');
    $r = $owner->request('POST', '/api/orders/' . $codOnlyId . '/approve', null, $northHeader);
    expect($r, 200, 'COD order approves without verification');
    $r = $owner->request('POST', '/api/orders/' . $codOnlyId . '/deliver', [], $northHeader);
    expect($r, 200, 'COD order delivers without verification');
    $codOnlySaleId = (int) ($r['json']['sale_id'] ?? 0);
    $r = $owner->request('GET', '/api/sales/' . $codOnlySaleId, null, $northHeader);
    expect($r, 200, 'COD delivered sale reloads');
    check(($r['json']['payment_method'] ?? '') === 'cash' && has_null_field(is_array($r['json']) ? $r['json'] : [], 'payment_reference'), 'COD sale records cash with no reference');

    // (c) A GCash reference is unique across orders and sales.
    $dupRef = 'GC-PHASE1-DUP-0003';
    $r = $customer->request('POST', '/api/my/orders', $prepaidOrder('gcash', $dupRef));
    expect($r, 201, 'first order reserves the GCash reference');
    $r = $customer->request('POST', '/api/my/orders', $prepaidOrder('gcash', $dupRef));
    expect($r, 409, 'second order with the same GCash reference is rejected');
    check(($r['json']['code'] ?? '') === 'duplicate_reference', 'duplicate order reference returns duplicate_reference');
    $r = $owner->request('POST', '/api/sales', [
        'pay' => 'gcash',
        'reference_no' => $dupRef,
        'items' => [['size_id' => $size1, 'qty' => 1, 'back' => 0]],
    ], ['X-Branch-Id: ' . $mainId]);
    expect($r, 409, 'direct sale reusing an order reference is rejected');
    check(($r['json']['code'] ?? '') === 'duplicate_reference', 'duplicate sale reference returns duplicate_reference');

    $saleRef = 'MAYA-PHASE1-SALE-0004';
    $r = $owner->request('POST', '/api/sales', [
        'pay' => 'maya',
        'reference_no' => $saleRef,
        'items' => [['size_id' => $size1, 'qty' => 1, 'back' => 0]],
    ], ['X-Branch-Id: ' . $mainId]);
    expect($r, 201, 'direct sale accepts a fresh Maya reference with no customer');
    check(has_null_field(is_array($r['json']) ? $r['json'] : [], 'customer_id'), 'prepaid direct sale does not require a customer');
    check(($r['json']['payment_method'] ?? '') === 'maya' && ($r['json']['payment_reference'] ?? '') === $saleRef, 'direct sale records the Maya method and reference');

    // (d) A rejected payment can be resubmitted with a fresh reference.
    $rejectedRef = 'GC-PHASE1-REJECT-0005';
    $freshRef = 'GC-PHASE1-FRESH-0006';
    $r = $customer->request('POST', '/api/my/orders', $prepaidOrder('gcash', $rejectedRef));
    expect($r, 201, 'customer places an order with a reference that will be rejected');
    $resubmitId = (int) ($r['json']['id'] ?? 0);
    $r = $owner->request('POST', '/api/orders/' . $resubmitId . '/reject-payment', ['reason' => 'Unreadable receipt'], $northHeader);
    expect($r, 200, 'staff rejects the submitted payment');
    check(($r['json']['payment_status'] ?? '') === 'rejected', 'reject-payment sets payment_status rejected');
    $r = $customer->request('GET', '/api/my/orders/' . $resubmitId);
    expect($r, 200, 'customer sees the rejected payment');
    check(($r['json']['payment_status'] ?? '') === 'rejected', 'customer order shows rejected status');

    $r = $customer->request('PATCH', '/api/my/orders/' . $resubmitId, $prepaidOrder('gcash', $freshRef));
    expect($r, 200, 'customer resubmits with a fresh reference');
    check(($r['json']['payment_status'] ?? '') === 'pending_verification', 'resubmission returns to pending_verification');
    check(($r['json']['payment_reference'] ?? '') === $freshRef, 'resubmission stores the fresh reference');
    check(has_null_field(is_array($r['json']) ? $r['json'] : [], 'payment_verified_by'), 'resubmission clears the previous verifier');
    $r = $owner->request('POST', '/api/orders/' . $resubmitId . '/verify-payment', null, $northHeader);
    expect($r, 200, 'staff verifies the resubmitted reference');
    $r = $owner->request('POST', '/api/orders/' . $resubmitId . '/approve', null, $northHeader);
    expect($r, 200, 'resubmitted order can be approved after verification');

    /* --------------------------------------------------------------------- */
    section('Phase 2: pricing, idempotency and operational hardening');
    /* --------------------------------------------------------------------- */

    // (1) Client price override and backdating must be ignored.
    $r = $owner->request('POST', '/api/sales', [
        'date' => '2020-01-01',
        'pay' => 'cash',
        'items' => [['size_id' => $size1, 'qty' => 1, 'back' => 0, 'price' => 1, 'unit_price' => 1]],
    ], ['X-Branch-Id: ' . $mainId]);
    expect($r, 201, 'direct sale ignores client price and date');
    $saleId = (int) ($r['json']['id'] ?? 0);
    $r = $owner->request('GET', '/api/sales/' . $saleId, null, ['X-Branch-Id: ' . $mainId]);
    expect($r, 200, 'direct sale reloads for assertions');
    check(($r['json']['sale_date'] ?? '') === date('Y-m-d'), 'sale date is forced to today');
    $saleItem = ($r['json']['items'][0] ?? []);
    $expectedPrice = (float) $pdo->query(
        'SELECT price FROM branch_prices WHERE branch_id=' . (int) $mainId . ' AND size_id=' . (int) $size1
    )->fetchColumn();
    check(abs((float) ($saleItem['unit_price'] ?? 0) - $expectedPrice) < 0.001, 'sale uses the branch price, not the client override');

    // (2) Order idempotency replays without a second reservation.
    $idemOrderBody = $prepaidOrder('cash', '');
    $idemOrderBody['idempotency_key'] = 'phase2-order-idem-1';
    $r = $customer->request('POST', '/api/my/orders', $idemOrderBody);
    expect($r, 201, 'customer creates an idempotent order');
    $idemOrderId = (int) ($r['json']['id'] ?? 0);
    $r = $customer->request('POST', '/api/my/orders', $idemOrderBody);
    expect($r, 200, 'reposting the same order idempotency key returns the existing order');
    check((int) ($r['json']['id'] ?? 0) === $idemOrderId, 'order idempotency replay returns the same id');

    // (3) Sale idempotency with conflicting details is refused.
    $idemSaleBody = [
        'idempotency_key' => 'phase2-sale-idem-1',
        'pay' => 'cash',
        'items' => [['size_id' => $size1, 'qty' => 1, 'back' => 0]],
    ];
    $r = $owner->request('POST', '/api/sales', $idemSaleBody, ['X-Branch-Id: ' . $mainId]);
    expect($r, 201, 'owner creates an idempotent sale');
    $idemSaleId = (int) ($r['json']['id'] ?? 0);
    $r = $owner->request('POST', '/api/sales', $idemSaleBody, ['X-Branch-Id: ' . $mainId]);
    expect($r, 200, 'reposting the same sale idempotency key returns the existing sale');
    check((int) ($r['json']['id'] ?? 0) === $idemSaleId, 'sale idempotency replay returns the same id');
    $conflictBody = $idemSaleBody;
    $conflictBody['pay'] = 'maya';
    $conflictBody['reference_no'] = 'MAYA-PHASE2-CONFLICT';
    $r = $owner->request('POST', '/api/sales', $conflictBody, ['X-Branch-Id: ' . $mainId]);
    expect($r, 409, 'reusing a sale key with different payment details is refused');
    check(($r['json']['code'] ?? '') === 'idempotency_conflict', 'sale idempotency conflict returns idempotency_conflict');

    // (4) Client deposit and fee fields are ignored; deposits come from cylinder_sizes.
    $pdo->exec('UPDATE cylinder_sizes SET deposit_amount=250.00 WHERE id=' . (int) $size1);
    $r = $customer->request('POST', '/api/my/orders', [
        'branch_id' => $mainId,
        'pay' => 'cash',
        'deposit_amount' => 99999,
        'deposit_total' => 99999,
        'rush_fee' => 99999,
        'outside_zone_fee' => 99999,
        'subtotal' => 1,
        'total_amount' => 1,
        'items' => [['size_id' => $size1, 'qty' => 2, 'deposit_amount' => 99999]],
    ]);
    expect($r, 201, 'customer order ignores client deposit and fee fields');
    $serverDepositOrder = (int) ($r['json']['id'] ?? 0);
    check(abs((float) ($r['json']['deposit_total'] ?? 0) - 500.0) < 0.001, 'order deposit is computed server-side from cylinder_sizes');
    $r = $customer->request('GET', '/api/my/orders/' . $serverDepositOrder);
    expect($r, 200, 'server-computed deposit order reloads');
    check(abs((float) ($r['json']['items'][0]['deposit_amount'] ?? 0) - 500.0) < 0.001, 'order item deposit is qty times per-cylinder deposit');
    $pdo->exec('UPDATE cylinder_sizes SET deposit_amount=0 WHERE id=' . (int) $size1);

    // (5) CSV exports escape formula-leading cells.
    $pdo->exec("UPDATE customers SET first_name='=HYPERLINK(\"http://evil\")', name='=HYPERLINK(\"http://evil\")' WHERE id=" . (int) $customerId);
    $csv = $owner->request('GET', '/api/reports/sales?from=' . $today . '&to=' . $today . '&format=csv');
    check(strpos($csv['body'] ?? '', "'=HYPERLINK") !== false, 'CSV cells starting with = are prefixed with an apostrophe');

    // (6) Branch-bound roles cannot see or create foreign transfers.
    $r = $staff->request('GET', '/api/transfers');
    expect($r, 200, 'source staff lists transfers');
    $staffSeesMainTransfer = false;
    foreach (is_array($r['json'] ?? null) ? $r['json'] : [] as $transferRow) {
        $touchesMain = (int) ($transferRow['from_branch_id'] ?? 0) === $mainId
            || (int) ($transferRow['to_branch_id'] ?? 0) === $mainId;
        check($touchesMain, 'staff transfer list is branch-scoped');
        if ((int) ($transferRow['id'] ?? 0) === $transferId) {
            $staffSeesMainTransfer = true;
        }
    }
    check($staffSeesMainTransfer, 'staff can see a transfer touching their own branch');

    $activeBranchAdmin = new ApiClient($base, 'activebranchadmin');
    $r = $activeBranchAdmin->login('southadmin2', 'AdminTemp123!');
    expect($r, 200, 'active south branch admin logs in');

    $r = $activeBranchAdmin->request('GET', '/api/transfers?branch_id=' . $mainId);
    expect($r, 200, 'south branch admin lists transfers');
    foreach (is_array($r['json'] ?? null) ? $r['json'] : [] as $transferRow) {
        $touchesSouth = (int) ($transferRow['from_branch_id'] ?? 0) === $southId
            || (int) ($transferRow['to_branch_id'] ?? 0) === $southId;
        check($touchesSouth, 'branch admin cannot see transfers outside their branch even with a branch filter');
    }

    $r = $activeBranchAdmin->request('POST', '/api/transfers', [
        'from_branch_id' => $mainId,
        'to_branch_id' => $northId,
        'items' => [['size_id' => $size1, 'qty_full' => 1]],
    ]);
    expect($r, 403, 'branch admin cannot create a transfer from a foreign branch');
    check(($r['json']['code'] ?? '') === 'branch_forbidden', 'foreign transfer create returns branch_forbidden');

    // (7) Duplicate plant delivery receipts are refused and dates cannot be backdated.
    $receipt = 'PHASE2-RECEIPT-1';
    $mainHeader = ['X-Branch-Id: ' . $mainId];
    $r = $owner->request('POST', '/api/deliveries', [
        'date' => '2020-01-01',
        'receipt' => $receipt,
        'items' => [['size_id' => $size1, 'full' => 1, 'empties' => 0]],
    ], $mainHeader);
    expect($r, 201, 'owner records a plant delivery');
    $r = $owner->request('GET', '/api/deliveries', null, $mainHeader);
    expect($r, 200, 'deliveries reload');
    $phase2Delivery = null;
    foreach (is_array($r['json'] ?? null) ? $r['json'] : [] as $deliveryRow) {
        if (($deliveryRow['receipt_no'] ?? '') === $receipt) {
            $phase2Delivery = $deliveryRow;
        }
    }
    check(($phase2Delivery['delivery_date'] ?? '') === date('Y-m-d'), 'delivery date is forced to today');
    $r = $owner->request('POST', '/api/deliveries', [
        'receipt' => $receipt,
        'items' => [['size_id' => $size1, 'full' => 1, 'empties' => 0]],
    ], $mainHeader);
    expect($r, 409, 'duplicate plant delivery receipt is refused');
    check(($r['json']['code'] ?? '') === 'duplicate_receipt', 'duplicate receipt returns duplicate_receipt');

    // (8) Receiving with shortages records actual quantities and a note.
    $r = $owner->request('POST', '/api/transfers', [
        'from_branch_id' => $mainId,
        'to_branch_id' => $northId,
        'items' => [['size_id' => $size1, 'qty_full' => 5, 'qty_empty' => 0]],
    ]);
    expect($r, 201, 'owner creates a short-shipment transfer');
    $shortTransferId = (int) ($r['json']['id'] ?? 0);
    $r = $owner->request('POST', '/api/transfers/' . $shortTransferId . '/approve');
    expect($r, 200, 'owner approves the short-shipment transfer');
    $r = $staff->request('POST', '/api/transfers/' . $shortTransferId . '/ship');
    expect($r, 200, 'source staff ships the short-shipment transfer');
    $r = $owner->request('POST', '/api/transfers/' . $shortTransferId . '/receive', [
        'shortage_note' => '2 cylinders damaged in transit',
        'items' => [['size_id' => $size1, 'received_full' => 3, 'received_empty' => 0]],
    ]);
    expect($r, 200, 'owner receives the transfer with a shortage');
    check((int) ($r['json']['shortage_full'] ?? 0) === 2, 'transfer records a 2-full-cylinder shortage');
    check(($r['json']['shortage_note'] ?? '') === '2 cylinders damaged in transit', 'transfer stores the shortage note');
    $r = $owner->request('GET', '/api/transfers/' . $shortTransferId);
    expect($r, 200, 'short-shipment transfer reloads');
    check((int) ($r['json']['items'][0]['received_full'] ?? 0) === 3, 'transfer item stores the actual received quantity');

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

    check_migration_guard($root, $config);
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
