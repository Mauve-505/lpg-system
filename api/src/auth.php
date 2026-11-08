<?php
declare(strict_types=1);

/**
 * Sessions, authentication, CSRF, rate limiting and branch scoping.
 * The database is re-read on every request: deactivating a user, changing a
 * role/password or deactivating a branch takes effect immediately.
 */

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $lifetime = (int) config('session.lifetime', 7200);
    $secureCfg = config('session.secure', null);
    $secure = $secureCfg === null ? is_https() : (bool) $secureCfg;
    $sameSite = (string) config('session.samesite', 'Lax');

    // Session files live outside the web root and survive Apache restarts.
    $savePath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'lpg_sessions';
    if (!is_dir($savePath)) {
        @mkdir($savePath, 0700, true);
    }
    if (is_dir($savePath) && is_writable($savePath)) {
        session_save_path($savePath);
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.gc_maxlifetime', (string) $lifetime);
    ini_set('session.sid_length', '48');
    ini_set('session.sid_bits_per_character', '5');

    session_name((string) config('session.name', 'lpg_session'));
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => $sameSite,
    ]);
    session_start();

    $now = time();
    if (isset($_SESSION['last_seen']) && ($now - (int) $_SESSION['last_seen']) > $lifetime) {
        destroy_session();
        session_start();
    }
    // Absolute cap so a session cannot be kept alive forever.
    if (isset($_SESSION['created_at']) && ($now - (int) $_SESSION['created_at']) > ($lifetime * 4)) {
        destroy_session();
        session_start();
    }
    $_SESSION['last_seen'] = $now;
    if (!isset($_SESSION['created_at'])) {
        $_SESSION['created_at'] = $now;
    }
}

function destroy_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $p['path'],
            'domain' => $p['domain'],
            'secure' => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_header(): string
{
    return (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
}

function require_csrf(): void
{
    if (!config('security.csrf', true)) {
        return;
    }
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }
    $sent = csrf_header();
    if ($sent === '' || empty($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], $sent)) {
        fail('Your session token is missing or expired. Refresh the page and try again.', 419, 'csrf_failed');
    }
}

function hash_password(string $password): string
{
    return password_hash($password, PASSWORD_DEFAULT);
}

function verify_password(string $password, string $hash): bool
{
    return password_verify($password, $hash);
}

function maybe_rehash_password(int $userId, string $password, string $hash): void
{
    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        q('UPDATE users SET password_hash=? WHERE id=?', [hash_password($password), $userId]);
    }
}

/**
 * Load the authenticated user from the database. Re-checks active flag,
 * role, branch, customer status and session version on every request.
 */
function current_user(bool $refresh = false): ?array
{
    static $cached = null;
    if ($refresh) {
        $cached = null;
    }
    if ($cached !== null) {
        return $cached;
    }
    if (empty($_SESSION['uid'])) {
        return null;
    }

    $u = row(
        'SELECT u.id, u.name, u.username, u.role, u.customer_id, u.branch_id,
                u.phone_verified, u.must_change_password, u.session_version, u.is_active,
                b.name AS branch_name, b.is_active AS branch_active,
                c.name AS customer_name, c.is_active AS customer_active,
                c.contact AS customer_contact, c.address AS customer_address
         FROM users u
         LEFT JOIN branches b ON b.id = u.branch_id
         LEFT JOIN customers c ON c.id = u.customer_id
         WHERE u.id = ?',
        [(int) $_SESSION['uid']]
    );

    if (!$u || (int) $u['is_active'] !== 1) {
        destroy_session();
        return null;
    }
    if ((int) $u['session_version'] !== (int) ($_SESSION['sv'] ?? 0)) {
        destroy_session();
        return null;
    }
    if (!in_array($u['role'], ['owner', 'staff', 'customer'], true)) {
        destroy_session();
        return null;
    }
    if ($u['role'] === 'staff') {
        if (empty($u['branch_id']) || (int) $u['branch_active'] !== 1) {
            destroy_session();
            return null;
        }
    }
    if ($u['role'] === 'customer') {
        if (empty($u['customer_id']) || (int) $u['customer_active'] !== 1) {
            destroy_session();
            return null;
        }
    }

    unset($u['branch_active'], $u['customer_active']);
    $u['id'] = (int) $u['id'];
    $u['customer_id'] = $u['customer_id'] === null ? null : (int) $u['customer_id'];
    $u['branch_id'] = $u['branch_id'] === null ? null : (int) $u['branch_id'];
    $u['must_change_password'] = (int) $u['must_change_password'];
    $u['phone_verified'] = (int) $u['phone_verified'];
    $u['session_version'] = (int) $u['session_version'];
    $cached = $u;
    return $cached;
}

function require_auth(): array
{
    $u = current_user();
    if (!$u) {
        fail('Please log in.', 401, 'unauthorized');
    }
    $path = (string) ($GLOBALS['LPG_PATH'] ?? '');
    if ($u['must_change_password'] === 1 && !in_array($path, ['me', 'me/password', 'logout', 'csrf', 'health'], true)) {
        fail('You must change your password before continuing.', 403, 'password_change_required');
    }
    return $u;
}

function require_role(array $roles): array
{
    $u = require_auth();
    if (!in_array($u['role'], $roles, true)) {
        fail('You do not have permission to do that.', 403, 'forbidden');
    }
    return $u;
}

function require_owner(): array
{
    return require_role(['owner']);
}

function require_staff_or_owner(): array
{
    return require_role(['owner', 'staff']);
}

function require_customer(): array
{
    return require_role(['customer']);
}

/**
 * Resolve the branch context for reads/writes.
 * - staff: ALWAYS their own branch_id from the DB. Client headers are ignored.
 * - customer: none (customer endpoints use the customer id).
 * - owner: X-Branch-Id header (or ?branch_id=) may be a branch id or "all".
 *   Writes require one concrete active branch.
 */
function branch_scope(?array $user = null, bool $forWrite = false): array
{
    $user = $user ?: require_auth();

    if ($user['role'] === 'staff') {
        $b = branch_row((int) $user['branch_id']);
        if (!$b || (int) $b['is_active'] !== 1) {
            fail('Your branch is inactive. Contact the owner.', 409, 'branch_inactive');
        }
        return ['mode' => 'branch', 'id' => (int) $b['id'], 'name' => $b['name']];
    }

    if ($user['role'] === 'customer') {
        return ['mode' => 'none', 'id' => null, 'name' => ''];
    }

    $raw = $_SERVER['HTTP_X_BRANCH_ID'] ?? ($_GET['branch_id'] ?? null);
    if ($raw === null || $raw === '') {
        if ($forWrite) {
            fail('Select a branch before saving.', 400, 'branch_required');
        }
        return ['mode' => 'all', 'id' => null, 'name' => 'All branches'];
    }
    $raw = trim((string) $raw);
    if (strtolower($raw) === 'all') {
        if ($forWrite) {
            fail('Select one branch before saving.', 400, 'branch_required');
        }
        return ['mode' => 'all', 'id' => null, 'name' => 'All branches'];
    }
    if (!preg_match('/^\d+$/', $raw) || (int) $raw < 1) {
        fail('Invalid branch selection.', 422, 'validation_error', ['field' => 'branch_id']);
    }
    $id = (int) $raw;
    $b = branch_row($id);
    if (!$b) {
        fail('Branch not found.', 404, 'not_found');
    }
    if ($forWrite && (int) $b['is_active'] !== 1) {
        fail('That branch is inactive. Choose an active branch.', 409, 'branch_inactive');
    }
    return ['mode' => 'branch', 'id' => $id, 'name' => $b['name']];
}

/** [sql fragment, params] for filtering a table by the current scope. */
function scope_sql(array $scope, string $column = 'branch_id'): array
{
    if ($scope['mode'] === 'branch') {
        return [' AND ' . $column . ' = ?', [$scope['id']]];
    }
    return ['', []];
}

function can_access_branch(array $user, int $branchId): bool
{
    if ($user['role'] === 'owner') {
        return branch_exists($branchId);
    }
    if ($user['role'] === 'staff') {
        return (int) $user['branch_id'] === $branchId;
    }
    return false;
}

function require_branch_access(array $user, int $branchId, bool $forWrite = false): array
{
    $b = branch_row($branchId);
    if (!$b) {
        fail('Branch not found.', 404, 'not_found');
    }
    if (!can_access_branch($user, $branchId)) {
        fail('You do not have access to that branch.', 403, 'branch_forbidden');
    }
    if ($forWrite && (int) $b['is_active'] !== 1) {
        fail('That branch is inactive.', 409, 'branch_inactive');
    }
    return $b;
}

/* ------------------------------------------------------------------------- */
/* Rate limiting                                                             */
/* ------------------------------------------------------------------------- */

function rate_limit_config(string $action): array
{
    $window = max(1, (int) config('security.login_window_minutes', 15));
    if ($action === 'signup') {
        return ['max_user' => 0, 'max_ip' => (int) config('security.signup_max_attempts', 5), 'window' => $window];
    }
    return [
        'max_user' => (int) config('security.login_max_attempts', 5),
        'max_ip' => (int) config('security.ip_max_attempts', 30),
        'window' => $window,
    ];
}

function check_rate_limit(string $action, ?string $username): void
{
    $cfg = rate_limit_config($action);
    $windowStart = (new DateTimeImmutable('now'))->modify('-' . $cfg['window'] . ' minutes')->format('Y-m-d H:i:s');
    $ip = client_ip();
    // Signup throttling must count every attempt (successful accounts create
    // rows too), otherwise an attacker can register unlimited accounts.
    $successClause = $action === 'signup' ? '' : ' AND success=0';

    if ($cfg['max_user'] > 0 && $username !== null && $username !== '') {
        $n = (int) val(
            'SELECT COUNT(*) FROM login_attempts
             WHERE action=? AND ip=? AND username=?' . $successClause . ' AND created_at>=?',
            [$action, $ip, $username, $windowStart],
            0
        );
        if ($n >= $cfg['max_user']) {
            $oldest = (string) val(
                'SELECT MIN(created_at) FROM login_attempts
                 WHERE action=? AND ip=? AND username=?' . $successClause . ' AND created_at>=?',
                [$action, $ip, $username, $windowStart],
                $windowStart
            );
            $retry = max(1, strtotime($oldest . ' +' . $cfg['window'] . ' minutes') - time());
            error_log('Rate limit hit for ' . $action . ' username=' . $username . ' ip=' . $ip);
            header('Retry-After: ' . $retry);
            fail('Too many attempts. Try again in ' . max(1, (int) ceil($retry / 60)) . ' minute(s).', 429, 'rate_limited');
        }
    }

    // Login also gets a per-username cap independent of IP, so rotating IPs
    // cannot brute-force one account.
    if ($action === 'login' && $username !== null && $username !== '') {
        $maxAccount = max(1, (int) config('security.login_max_user_attempts', 10));
        $nAccount = (int) val(
            'SELECT COUNT(*) FROM login_attempts
             WHERE action=? AND username=? AND success=0 AND created_at>=?',
            [$action, $username, $windowStart],
            0
        );
        if ($nAccount >= $maxAccount) {
            $oldest = (string) val(
                'SELECT MIN(created_at) FROM login_attempts
                 WHERE action=? AND username=? AND success=0 AND created_at>=?',
                [$action, $username, $windowStart],
                $windowStart
            );
            $retry = max(1, strtotime($oldest . ' +' . $cfg['window'] . ' minutes') - time());
            error_log('Account rate limit hit for username=' . $username);
            header('Retry-After: ' . $retry);
            fail('This account is temporarily locked. Try again in ' . max(1, (int) ceil($retry / 60)) . ' minute(s).', 429, 'rate_limited');
        }
    }

    $nIp = (int) val(
        'SELECT COUNT(*) FROM login_attempts
         WHERE action=? AND ip=?' . $successClause . ' AND created_at>=?',
        [$action, $ip, $windowStart],
        0
    );
    if ($nIp >= $cfg['max_ip']) {
        $oldest = (string) val(
            'SELECT MIN(created_at) FROM login_attempts
             WHERE action=? AND ip=?' . $successClause . ' AND created_at>=?',
            [$action, $ip, $windowStart],
            $windowStart
        );
        $retry = max(1, strtotime($oldest . ' +' . $cfg['window'] . ' minutes') - time());
        error_log('IP rate limit hit for ' . $action . ' ip=' . $ip);
        header('Retry-After: ' . $retry);
        fail('Too many attempts from this network. Try again later.', 429, 'rate_limited');
    }
}

function record_attempt(string $action, ?string $username, bool $success): void
{
    q(
        'INSERT INTO login_attempts (action, ip, username, success, user_agent) VALUES (?,?,?,?,?)',
        [$action, client_ip(), $username === null ? null : mb_substr($username, 0, 80), $success ? 1 : 0, mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]
    );
    // Opportunistic cleanup; keeps the table small without a cron job.
    if (random_int(1, 50) === 1) {
        q('DELETE FROM login_attempts WHERE created_at < ?', [(new DateTimeImmutable('now'))->modify('-7 days')->format('Y-m-d H:i:s')]);
    }
}

function clear_login_failures(string $action, string $username): void
{
    q('DELETE FROM login_attempts WHERE action=? AND ip=? AND username=? AND success=0', [$action, client_ip(), $username]);
}

/* ------------------------------------------------------------------------- */
/* Session lifecycle                                                         */
/* ------------------------------------------------------------------------- */

function session_login(array $userRow): array
{
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $userRow['id'];
    $_SESSION['sv'] = (int) $userRow['session_version'];
    $_SESSION['created_at'] = time();
    $_SESSION['last_seen'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    q('UPDATE users SET last_login_at=? WHERE id=?', [now_iso(), (int) $userRow['id']]);
    return current_user(true);
}

function public_user(array $u): array
{
    return [
        'id' => (int) $u['id'],
        'name' => $u['name'],
        'username' => $u['username'],
        'role' => $u['role'],
        'customer_id' => $u['customer_id'],
        'branch_id' => $u['branch_id'],
        'branch_name' => $u['branch_name'] ?? null,
        'phone_verified' => (int) ($u['phone_verified'] ?? 0),
        'must_change_password' => (int) ($u['must_change_password'] ?? 0),
        'csrf_token' => csrf_token(),
    ];
}

/* ------------------------------------------------------------------------- */
/* Password reset / phone verification design                                */
/* ------------------------------------------------------------------------- */

function create_password_reset_token(int $userId): string
{
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token . (string) config('session_secret', ''));
    q(
        'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?,?,?)',
        [$userId, $hash, (new DateTimeImmutable('now'))->modify('+60 minutes')->format('Y-m-d H:i:s')]
    );
    return $token;
}

function consume_password_reset_token(string $token): ?int
{
    $hash = hash('sha256', $token . (string) config('session_secret', ''));
    $r = row(
        'SELECT id, user_id FROM password_resets WHERE token_hash=? AND used_at IS NULL AND expires_at>=?',
        [$hash, now_iso()]
    );
    if (!$r) {
        return null;
    }
    q('UPDATE password_resets SET used_at=? WHERE id=?', [now_iso(), (int) $r['id']]);
    return (int) $r['user_id'];
}

/**
 * OTP design: codes are stored as SHA-256 hashes with the app secret, expire,
 * and are single-use. A real SMS provider should be wired into send_otp().
 * When no provider is configured the code is written to the error log only
 * (never returned by the API) so local testing is possible.
 */
function create_otp(?int $userId, string $phone, string $purpose = 'signup'): string
{
    $code = (string) random_int(100000, 999999);
    $ttl = max(1, (int) config('otp.ttl_minutes', 10));
    q(
        'INSERT INTO phone_verifications (user_id, phone, code_hash, purpose, expires_at) VALUES (?,?,?,?,?)',
        [$userId, $phone, hash('sha256', $code . (string) config('session_secret', '')), $purpose, (new DateTimeImmutable('now'))->modify('+' . $ttl . ' minutes')->format('Y-m-d H:i:s')]
    );
    send_otp($phone, $code, $purpose);
    return $code;
}

function send_otp(string $phone, string $code, string $purpose): void
{
    $provider = (string) config('otp.provider', '');
    if ($provider === '') {
        error_log('[OTP:dev] phone=' . $phone . ' purpose=' . $purpose . ' code=' . $code);
        return;
    }
    // Integrate an SMS gateway here. Never hardcode credentials; read them
    // from config/environment. The API must never return the code.
    throw new RuntimeException('OTP provider is configured but send_otp() is not implemented.');
}

function verify_otp(string $phone, string $code, string $purpose = 'signup'): bool
{
    $r = row(
        'SELECT * FROM phone_verifications
         WHERE phone=? AND purpose=? AND consumed_at IS NULL AND expires_at>=?
         ORDER BY id DESC LIMIT 1',
        [$phone, $purpose, now_iso()]
    );
    if (!$r) {
        return false;
    }
    $max = max(1, (int) config('otp.max_attempts', 5));
    if ((int) $r['attempts'] >= $max) {
        return false;
    }
    $expected = hash('sha256', $code . (string) config('session_secret', ''));
    q('UPDATE phone_verifications SET attempts=attempts+1 WHERE id=?', [(int) $r['id']]);
    if (!hash_equals((string) $r['code_hash'], $expected)) {
        return false;
    }
    q('UPDATE phone_verifications SET consumed_at=? WHERE id=?', [now_iso(), (int) $r['id']]);
    return true;
}
