<?php
declare(strict_types=1);

/** Roles that must belong to exactly one active branch. */
function user_branch_roles(): array
{
    return ['branch_admin', 'staff', 'delivery'];
}

function user_all_roles(): array
{
    return ['owner', 'branch_admin', 'staff', 'delivery', 'customer'];
}

function api_users_list(): void
{
    $u = require_role(['owner', 'branch_admin']);
    $branchManager = $u['role'] === 'branch_admin';
    $p = pagination();
    $where = '1=1';
    $params = [];
    if ($branchManager) {
        // A branch admin only ever sees the team of their own branch.
        $where .= ' AND u.branch_id=?';
        $params[] = (int) $u['branch_id'];
        $where .= " AND u.role IN ('branch_admin','staff','delivery')";
    }
    if (($role = query_str('role', 20)) !== '') {
        if (!in_array($role, user_all_roles(), true)) {
            fail('Invalid role filter.', 422, 'validation_error');
        }
        if ($branchManager && !in_array($role, user_branch_roles(), true)) {
            fail('You can only view your own branch roles.', 403, 'forbidden');
        }
        $where .= ' AND u.role=?';
        $params[] = $role;
    }
    if ($p['q'] !== '') {
        $where .= ' AND (u.name LIKE ? OR u.username LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)';
        $like = '%' . $p['q'] . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    $total = (int) val('SELECT COUNT(*) FROM users u WHERE ' . $where, $params, 0);
    $st = db()->prepare(
        'SELECT u.id, u.name, u.first_name, u.middle_name, u.last_name, u.name_suffix,
                u.username, u.role, u.customer_id, u.branch_id, u.is_active,
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
    $u = require_role(['owner', 'branch_admin']);
    $branchManager = $u['role'] === 'branch_admin';
    $person = person_name_input();
    $name = $person['name'];
    $username = input_str('username', 'Username', 3, 30, true, '/^[A-Za-z0-9_.-]+$/');
    $password = (string) body_value('password', '');
    $min = (int) config('security.password_min_length', 8);
    if (strlen($password) < $min) {
        fail('Password must be at least ' . $min . ' characters.', 422, 'validation_error', ['field' => 'password']);
    }
    $role = input_enum('role', 'Role', user_all_roles());
    $branchId = input_int('branch_id', 'Branch', 1, null, false);
    $contact = input_str('contact', 'Contact number', 0, 50, false);
    $address = address_input('', 'Address', false);

    if ($branchManager) {
        // Branch admins add staff and riders to their own branch; promoting a
        // branch admin goes through POST /branches/{id}/admin instead.
        if (!in_array($role, ['staff', 'delivery'], true)) {
            fail('Branch admins can only add staff or delivery riders.', 403, 'forbidden');
        }
        if ($branchId !== null && (int) $branchId !== (int) $u['branch_id']) {
            fail('You can only add people to your own branch.', 403, 'branch_forbidden');
        }
        $branchId = (int) $u['branch_id'];
    }

    if (in_array($role, user_branch_roles(), true)) {
        if (!$branchId) {
            fail('This role must be assigned to a branch.', 422, 'validation_error', ['field' => 'branch_id']);
        }
        require_active_branch((int) $branchId);
    } else {
        $branchId = null;
    }
    if (val('SELECT id FROM users WHERE username=?', [$username])) {
        fail('That username is already taken.', 409, 'username_taken');
    }

    try {
        $id = tx(function () use ($person, $username, $password, $role, $branchId, $contact, $address) {
            $customerId = null;
            if ($role === 'customer') {
                q(
                    'INSERT INTO customers (name, first_name, middle_name, last_name, name_suffix,
                        contact, address, street, barangay, city, province, region, postal_code, latitude, longitude)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [
                        $person['name'], $person['first_name'], $person['middle_name'], $person['last_name'], $person['name_suffix'],
                        $contact === '' ? null : $contact, $address['address'], $address['street'], $address['barangay'],
                        $address['city'], $address['province'], $address['region'], $address['postal_code'],
                        $address['latitude'], $address['longitude'],
                    ]
                );
                $customerId = (int) db()->lastInsertId();
            }
            q(
                'INSERT INTO users (name, first_name, middle_name, last_name, name_suffix,
                    username, password_hash, role, customer_id, branch_id, phone, must_change_password)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,1)',
                [
                    $person['name'], $person['first_name'], $person['middle_name'], $person['last_name'], $person['name_suffix'],
                    $username, hash_password($password), $role, $customerId, $branchId, $contact === '' ? null : $contact,
                ]
            );
            $id = (int) db()->lastInsertId();
            if ($role === 'branch_admin' && $branchId !== null) {
                q('UPDATE branches SET branch_admin_id=? WHERE id=? AND branch_admin_id IS NULL', [$id, (int) $branchId]);
            }
            return $id;
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
    $u = require_role(['owner', 'branch_admin']);
    $branchManager = $u['role'] === 'branch_admin';
    $id = (int) $params[0];
    $target = row('SELECT * FROM users WHERE id=?', [$id]);
    if (!$target) {
        fail('User not found.', 404, 'not_found');
    }
    $person = person_name_input();
    $name = $person['name'];
    $username = input_str('username', 'Username', 3, 30, true, '/^[A-Za-z0-9_.-]+$/');
    $role = input_enum('role', 'Role', user_all_roles());
    $branchId = input_int('branch_id', 'Branch', 1, null, false);
    $isActive = input_bool('is_active', true);

    if ($branchManager) {
        // Staff and riders of the same branch only. Branch admin handover is
        // handled by POST /branches/{id}/admin.
        if ((int) ($target['branch_id'] ?? 0) !== (int) $u['branch_id']
            || !in_array($target['role'], ['staff', 'delivery'], true)) {
            fail('You can only edit staff and riders in your own branch.', 403, 'branch_forbidden');
        }
        if (!in_array($role, ['staff', 'delivery'], true)) {
            fail('Branch admins can only assign staff or delivery roles.', 403, 'forbidden');
        }
        if ($branchId !== null && (int) $branchId !== (int) $u['branch_id']) {
            fail('You can only manage your own branch.', 403, 'branch_forbidden');
        }
        $branchId = (int) $u['branch_id'];
    }

    if ($id === (int) $u['id'] && ($role !== 'owner' || !$isActive)) {
        fail('You cannot demote or deactivate your own owner account.', 409, 'self_protection');
    }
    if (in_array($role, user_branch_roles(), true)) {
        if (!$branchId) {
            fail('This role must be assigned to a branch.', 422, 'validation_error', ['field' => 'branch_id']);
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
    tx(function () use ($id, $target, $person, $name, $username, $role, $branchId, $isActive, $changed) {
        q(
            'UPDATE users SET name=?, first_name=?, middle_name=?, last_name=?, name_suffix=?,
                username=?, role=?, branch_id=?, is_active=?, session_version=session_version+? WHERE id=?',
            [
                $name, $person['first_name'], $person['middle_name'], $person['last_name'], $person['name_suffix'],
                $username, $role, $branchId, $isActive ? 1 : 0, $changed ? 1 : 0, $id,
            ]
        );
        // Keep the branch admin link in sync with role changes.
        if ($target['role'] === 'branch_admin') {
            q('UPDATE branches SET branch_admin_id=NULL WHERE branch_admin_id=?', [$id]);
        }
        if ($role === 'branch_admin' && $branchId !== null) {
            q('UPDATE branches SET branch_admin_id=? WHERE id=? AND (branch_admin_id IS NULL OR branch_admin_id=?)', [$id, (int) $branchId, $id]);
        }
    });
    audit($u, 'update', 'user', $id,
        ['name' => $target['name'], 'username' => $target['username'], 'role' => $target['role'], 'branch_id' => $target['branch_id'], 'is_active' => $target['is_active']],
        ['name' => $name, 'username' => $username, 'role' => $role, 'branch_id' => $branchId, 'is_active' => $isActive ? 1 : 0],
        $branchId
    );
    json_response(['ok' => 1]);
}

function api_users_delete(array $params): void
{
    $u = require_role(['owner', 'branch_admin']);
    $id = (int) $params[0];
    if ($id === (int) $u['id']) {
        fail('You cannot remove your own account.', 409, 'self_protection');
    }
    $target = row('SELECT * FROM users WHERE id=?', [$id]);
    if (!$target) {
        fail('User not found.', 404, 'not_found');
    }
    if ($u['role'] === 'branch_admin'
        && ((int) ($target['branch_id'] ?? 0) !== (int) $u['branch_id']
            || !in_array($target['role'], ['staff', 'delivery'], true))) {
        fail('You can only deactivate staff and riders in your own branch.', 403, 'branch_forbidden');
    }
    tx(function () use ($id) {
        q('UPDATE users SET is_active=0, session_version=session_version+1 WHERE id=?', [$id]);
        q('UPDATE branches SET branch_admin_id=NULL WHERE branch_admin_id=?', [$id]);
    });
    audit($u, 'deactivate', 'user', $id, ['is_active' => 1], ['is_active' => 0], $target['branch_id'] ? (int) $target['branch_id'] : null);
    ok(['ok' => 1]);
}

function api_users_reset_password(array $params): void
{
    $u = require_role(['owner', 'branch_admin']);
    $id = (int) $params[0];
    $target = row('SELECT * FROM users WHERE id=?', [$id]);
    if (!$target) {
        fail('User not found.', 404, 'not_found');
    }
    if ($u['role'] === 'branch_admin'
        && ((int) ($target['branch_id'] ?? 0) !== (int) $u['branch_id']
            || !in_array($target['role'], ['staff', 'delivery'], true))) {
        fail('You can only reset staff and riders in your own branch.', 403, 'branch_forbidden');
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
