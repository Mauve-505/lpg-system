<?php
declare(strict_types=1);

function api_users_list(): void
{
    require_owner();
    $p = pagination();
    $where = '1=1';
    $params = [];
    if (($role = query_str('role', 10)) !== '') {
        if (!in_array($role, ['owner', 'staff', 'customer'], true)) {
            fail('Invalid role filter.', 422, 'validation_error');
        }
        $where .= ' AND u.role=?';
        $params[] = $role;
    }
    if ($p['q'] !== '') {
        $where .= ' AND (u.name LIKE ? OR u.username LIKE ?)';
        $like = '%' . $p['q'] . '%';
        $params[] = $like;
        $params[] = $like;
    }
    $total = (int) val('SELECT COUNT(*) FROM users u WHERE ' . $where, $params, 0);
    $st = db()->prepare(
        'SELECT u.id, u.name, u.username, u.role, u.customer_id, u.branch_id, u.is_active,
                u.must_change_password, u.last_login_at, b.name branch_name
         FROM users u LEFT JOIN branches b ON b.id=u.branch_id
         WHERE ' . $where . ' ORDER BY u.id LIMIT ? OFFSET ?'
    );
    bind_page($st, $params, $p['per'], $p['offset']);
    $st->execute();
    paginate_headers($total, $p['page'], $p['per']);
    json_response($st->fetchAll());
}

function api_users_create(): void
{
    $u = require_owner();
    $name = input_str('name', 'Full name', 2, 100);
    $username = input_str('username', 'Username', 3, 30, true, '/^[A-Za-z0-9_.-]+$/');
    $password = (string) body_value('password', '');
    $min = (int) config('security.password_min_length', 8);
    if (strlen($password) < $min) {
        fail('Password must be at least ' . $min . ' characters.', 422, 'validation_error', ['field' => 'password']);
    }
    $role = input_enum('role', 'Role', ['owner', 'staff', 'customer']);
    $branchId = input_int('branch_id', 'Branch', 1, null, false);
    $contact = input_str('contact', 'Contact number', 0, 50, false);
    $address = input_str('address', 'Address', 0, 200, false);

    if ($role === 'staff') {
        if (!$branchId) {
            fail('Staff must be assigned to a branch.', 422, 'validation_error', ['field' => 'branch_id']);
        }
        require_active_branch((int) $branchId);
    } else {
        $branchId = null;
    }
    if (val('SELECT id FROM users WHERE username=?', [$username])) {
        fail('That username is already taken.', 409, 'username_taken');
    }

    try {
        $id = tx(function () use ($name, $username, $password, $role, $branchId, $contact, $address) {
            $customerId = null;
            if ($role === 'customer') {
                q('INSERT INTO customers (name, contact, address) VALUES (?,?,?)', [$name, $contact === '' ? null : $contact, $address === '' ? null : $address]);
                $customerId = (int) db()->lastInsertId();
            }
            q(
                'INSERT INTO users (name, username, password_hash, role, customer_id, branch_id, phone, must_change_password)
                 VALUES (?,?,?,?,?,?,?,1)',
                [$name, $username, hash_password($password), $role, $customerId, $branchId, $contact === '' ? null : $contact]
            );
            return (int) db()->lastInsertId();
        });
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            fail('That username is already taken.', 409, 'username_taken');
        }
        throw $e;
    }

    audit($u, 'create', 'user', $id, null, ['username' => $username, 'role' => $role, 'branch_id' => $branchId], $branchId);
    json_response(['ok' => 1, 'id' => $id], 201);
}

function api_users_update(array $params): void
{
    $u = require_owner();
    $id = (int) $params[0];
    $target = row('SELECT * FROM users WHERE id=?', [$id]);
    if (!$target) {
        fail('User not found.', 404, 'not_found');
    }
    $name = input_str('name', 'Full name', 2, 100);
    $username = input_str('username', 'Username', 3, 30, true, '/^[A-Za-z0-9_.-]+$/');
    $role = input_enum('role', 'Role', ['owner', 'staff', 'customer']);
    $branchId = input_int('branch_id', 'Branch', 1, null, false);
    $isActive = input_bool('is_active', true);

    if ($id === (int) $u['id'] && ($role !== 'owner' || !$isActive)) {
        fail('You cannot demote or deactivate your own owner account.', 409, 'self_protection');
    }
    if ($role === 'staff') {
        if (!$branchId) {
            fail('Staff must be assigned to a branch.', 422, 'validation_error', ['field' => 'branch_id']);
        }
        require_active_branch((int) $branchId);
    } else {
        $branchId = null;
    }
    if ($role === 'customer' && !$target['customer_id']) {
        fail('This user has no linked customer record.', 409, 'invalid_role_change');
    }
    if (val('SELECT id FROM users WHERE username=? AND id<>?', [$username, $id])) {
        fail('That username is already taken.', 409, 'username_taken');
    }

    $changed = (int) $target['role'] !== $role || (int) ($target['branch_id'] ?? 0) !== (int) ($branchId ?? 0) || (int) $target['is_active'] !== ($isActive ? 1 : 0);
    q(
        'UPDATE users SET name=?, username=?, role=?, branch_id=?, is_active=?,
            session_version=session_version+? WHERE id=?',
        [$name, $username, $role, $branchId, $isActive ? 1 : 0, $changed ? 1 : 0, $id]
    );
    audit($u, 'update', 'user', $id,
        ['name' => $target['name'], 'username' => $target['username'], 'role' => $target['role'], 'branch_id' => $target['branch_id'], 'is_active' => $target['is_active']],
        ['name' => $name, 'username' => $username, 'role' => $role, 'branch_id' => $branchId, 'is_active' => $isActive ? 1 : 0],
        $branchId
    );
    json_response(['ok' => 1]);
}

function api_users_delete(array $params): void
{
    $u = require_owner();
    $id = (int) $params[0];
    if ($id === (int) $u['id']) {
        fail('You cannot remove your own account.', 409, 'self_protection');
    }
    $target = row('SELECT * FROM users WHERE id=?', [$id]);
    if (!$target) {
        fail('User not found.', 404, 'not_found');
    }
    q('UPDATE users SET is_active=0, session_version=session_version+1 WHERE id=?', [$id]);
    audit($u, 'deactivate', 'user', $id, ['is_active' => 1], ['is_active' => 0], $target['branch_id'] ? (int) $target['branch_id'] : null);
    ok(['ok' => 1]);
}

function api_users_reset_password(array $params): void
{
    $u = require_owner();
    $id = (int) $params[0];
    $target = row('SELECT * FROM users WHERE id=?', [$id]);
    if (!$target) {
        fail('User not found.', 404, 'not_found');
    }
    if ((int) $target['is_active'] !== 1) {
        fail('Activate the user before resetting the password.', 409, 'user_inactive');
    }
    $temp = 'Tmp-' . bin2hex(random_bytes(5)) . random_int(10, 99);
    q(
        'UPDATE users SET password_hash=?, must_change_password=1, session_version=session_version+1 WHERE id=?',
        [hash_password($temp), $id]
    );
    audit($u, 'password_reset', 'user', $id, null, ['must_change_password' => 1], $target['branch_id'] ? (int) $target['branch_id'] : null);
    // Returned exactly once. The owner must hand it over securely.
    json_response(['ok' => 1, 'temporary_password' => $temp]);
}
