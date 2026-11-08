<?php
declare(strict_types=1);

function api_health(): void
{
    ok(['ok' => true, 'time' => now_iso()]);
}

function api_csrf(): void
{
    ok(['csrf_token' => csrf_token()]);
}

function api_login(): void
{
    $body = read_json_body();
    $username = trim((string) ($body['username'] ?? ''));
    $password = (string) ($body['password'] ?? '');
    $username = mb_substr($username, 0, 50);

    if ($username === '' || $password === '') {
        fail('Enter your username and password.', 422, 'validation_error');
    }

    check_rate_limit('login', $username);

    $u = row('SELECT * FROM users WHERE username=?', [$username]);
    // Constant-ish work even for unknown usernames.
    $dummy = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
    $okPassword = $u ? verify_password($password, (string) $u['password_hash']) : verify_password($password, $dummy);

    if (!$u || !$okPassword || (int) $u['is_active'] !== 1) {
        record_attempt('login', $username, false);
        fail('Wrong username or password.', 401, 'invalid_credentials');
    }

    if ($u['role'] === 'staff') {
        $b = $u['branch_id'] ? branch_row((int) $u['branch_id']) : null;
        if (!$b || (int) $b['is_active'] !== 1) {
            record_attempt('login', $username, false);
            fail('Your branch is inactive. Contact the owner.', 403, 'branch_inactive');
        }
    }
    if ($u['role'] === 'customer') {
        $c = $u['customer_id'] ? row('SELECT is_active FROM customers WHERE id=?', [(int) $u['customer_id']]) : null;
        if (!$c || (int) $c['is_active'] !== 1) {
            record_attempt('login', $username, false);
            fail('This account is disabled.', 403, 'account_disabled');
        }
    }

    maybe_rehash_password((int) $u['id'], $password, (string) $u['password_hash']);
    clear_login_failures('login', $username);
    record_attempt('login', $username, true);

    $user = session_login($u);
    audit($user, 'login', 'user', $user['id'], null, ['username' => $username], $user['branch_id']);
    json_response(public_user($user));
}

function api_logout(): void
{
    $u = current_user();
    if ($u) {
        audit($u, 'logout', 'user', $u['id'], null, null, $u['branch_id']);
    }
    destroy_session();
    ok();
}

function api_register(): void
{
    check_rate_limit('signup', null);
    $name = input_str('name', 'Full name', 2, 100);
    $username = input_str('username', 'Username', 3, 30, true, '/^[A-Za-z0-9_.-]+$/');
    $contact = input_str('contact', 'Mobile number', 7, 40, true, '/^[0-9+()\-\s]+$/');
    $address = input_str('address', 'Delivery address', 5, 200);
    $password = (string) body_value('password', '');
    $min = (int) config('security.password_min_length', 8);
    if (strlen($password) < $min) {
        fail('Password must be at least ' . $min . ' characters.', 422, 'validation_error', ['field' => 'password']);
    }
    if (strlen($password) > 200) {
        fail('Password is too long.', 422, 'validation_error', ['field' => 'password']);
    }

    // OTP is required only when an SMS provider is configured.
    if ((string) config('otp.provider', '') !== '') {
        $code = input_str('otp_code', 'Verification code', 6, 6, true, '/^\d{6}$/');
        if (!verify_otp($contact, $code, 'signup')) {
            record_attempt('signup', $username, false);
            fail('The verification code is wrong or expired.', 422, 'otp_invalid');
        }
    }

    if (val('SELECT id FROM users WHERE username=?', [$username])) {
        record_attempt('signup', $username, false);
        fail('That username is already taken.', 409, 'username_taken');
    }

    try {
        $userId = tx(function () use ($name, $contact, $address, $username, $password) {
            q('INSERT INTO customers (name, contact, address) VALUES (?,?,?)', [$name, $contact, $address]);
            $cid = (int) db()->lastInsertId();
            q(
                "INSERT INTO users (name, username, password_hash, role, customer_id, phone, must_change_password)
                 VALUES (?,?,?,'customer',?,?,0)",
                [$name, $username, hash_password($password), $cid, $contact]
            );
            return ['uid' => (int) db()->lastInsertId(), 'cid' => $cid];
        });
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            record_attempt('signup', $username, false);
            fail('That username is already taken.', 409, 'username_taken');
        }
        throw $e;
    }

    record_attempt('signup', $username, true);
    $u = row('SELECT * FROM users WHERE id=?', [$userId['uid']]);
    $user = session_login($u);
    audit($user, 'register', 'user', $user['id'], null, ['username' => $username, 'customer_id' => $userId['cid']], null);
    json_response(public_user($user), 201);
}

/** Start a phone-verification challenge (design; provider must be configured). */
function api_request_otp(): void
{
    $phone = input_str('phone', 'Mobile number', 7, 40, true, '/^[0-9+()\-\s]+$/');
    // Count every request, not only failures, to stop SMS pumping.
    $windowStart = (new DateTimeImmutable('now'))->modify('-15 minutes')->format('Y-m-d H:i:s');
    $n = (int) val(
        'SELECT COUNT(*) FROM login_attempts WHERE action=? AND ip=? AND created_at>=?',
        ['signup', client_ip(), $windowStart],
        0
    );
    if ($n >= max(1, (int) config('security.signup_max_attempts', 5))) {
        header('Retry-After: 900');
        fail('Too many verification requests. Try again later.', 429, 'rate_limited');
    }
    record_attempt('signup', null, false);
    create_otp(null, $phone, 'signup');
    ok(['ok' => 1, 'message' => 'If the number can receive SMS, a code has been sent.']);
}

function api_me(): void
{
    $u = require_auth();
    $payload = public_user($u);
    // Let the app show the branch name without a second round trip.
    if ($u['role'] === 'staff') {
        $payload['branch_name'] = $u['branch_name'];
    }
    json_response($payload);
}

function api_change_password(): void
{
    $u = require_auth();
    $current = (string) body_value('current_password', '');
    $new = (string) body_value('new_password', '');
    $min = (int) config('security.password_min_length', 8);
    if ($current === '') {
        fail('Enter your current password.', 422, 'validation_error', ['field' => 'current_password']);
    }
    if (strlen($new) < $min) {
        fail('New password must be at least ' . $min . ' characters.', 422, 'validation_error', ['field' => 'new_password']);
    }
    if (strlen($new) > 200) {
        fail('New password is too long.', 422, 'validation_error', ['field' => 'new_password']);
    }
    if ($current === $new) {
        fail('New password must be different from the current one.', 422, 'validation_error', ['field' => 'new_password']);
    }
    $dbUser = row('SELECT password_hash FROM users WHERE id=?', [$u['id']]);
    if (!$dbUser || !verify_password($current, (string) $dbUser['password_hash'])) {
        record_attempt('login', $u['username'], false);
        fail('Current password is wrong.', 401, 'invalid_credentials');
    }

    $newVersion = (int) $u['session_version'] + 1;
    q(
        'UPDATE users SET password_hash=?, must_change_password=0, session_version=? WHERE id=?',
        [hash_password($new), $newVersion, $u['id']]
    );
    // Invalidate every other session immediately.
    $_SESSION['sv'] = $newVersion;
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    session_regenerate_id(true);
    $fresh = current_user(true);
    audit($fresh, 'password_change', 'user', $fresh['id'], null, ['session_version' => $newVersion], $fresh['branch_id']);
    json_response(public_user($fresh));
}

function api_branches_public(): void
{
    $rows = rows(
        'SELECT id, name, code, address, contact_phone, service_area_notes, operating_hours
         FROM branches WHERE is_active=1 ORDER BY name'
    );
    json_response(array_map(static function ($b) {
        $b['id'] = (int) $b['id'];
        return $b;
    }, $rows));
}

function api_sizes_list(): void
{
    $branchId = query_int('branch_id', null, 1);

    // This endpoint is public, but a logged-in owner/staff member should see
    // the active branch's prices by default instead of global placeholders.
    if ($branchId === null) {
        $u = current_user();
        if ($u !== null) {
            if ($u['role'] === 'staff' && $u['branch_id'] !== null) {
                $branchId = (int) $u['branch_id'];
            } elseif ($u['role'] === 'owner') {
                $raw = trim((string) ($_SERVER['HTTP_X_BRANCH_ID'] ?? ''));
                if ($raw !== '' && strtolower($raw) !== 'all' && preg_match('/^\d+$/', $raw) && (int) $raw > 0) {
                    $branchId = (int) $raw;
                }
            }
        }
    }

    if ($branchId !== null) {
        $b = branch_row($branchId);
        if (!$b || (int) $b['is_active'] !== 1) {
            fail('Branch not found or inactive.', 404, 'not_found');
        }
    }
    $sizes = rows('SELECT id, label, selling_price, valve_type FROM cylinder_sizes WHERE is_active=1 ORDER BY id');
    $out = [];
    foreach ($sizes as $s) {
        $id = (int) $s['id'];
        $price = $branchId !== null ? resolve_price($branchId, $id) : (float) $s['selling_price'];
        $out[] = [
            'id' => $id,
            'label' => $s['label'],
            'selling_price' => $price,
            'global_price' => (float) $s['selling_price'],
            'valve_type' => $s['valve_type'],
            'branch_id' => $branchId,
            'branch_price' => $branchId !== null && row('SELECT price FROM branch_prices WHERE branch_id=? AND size_id=?', [$branchId, $id]) !== null,
        ];
    }
    json_response($out);
}
