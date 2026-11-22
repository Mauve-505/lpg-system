<?php
declare(strict_types=1);

/**
 * Branch administration (owner only) and per-branch price lists.
 */

/** Standard same-day rush add-on when a branch does not choose its own fee. */
const BRANCH_DEFAULT_RUSH_FEE = 50.0;

function branch_payload(array $b): array
{
    $admin = null;
    if (!empty($b['branch_admin_id'])) {
        $admin = row('SELECT id, name, username FROM users WHERE id=?', [(int) $b['branch_admin_id']]);
        if ($admin) {
            $admin['id'] = (int) $admin['id'];
        }
    }
    return array_merge([
        'id' => (int) $b['id'],
        'name' => $b['name'],
        'code' => $b['code'],
        'address' => $b['address'],
        'contact_phone' => $b['contact_phone'],
        'gcash_number' => $b['gcash_number'] ?? null,
        'gcash_account_name' => $b['gcash_account_name'] ?? null,
        'maya_number' => $b['maya_number'] ?? null,
        'maya_account_name' => $b['maya_account_name'] ?? null,
        'service_area_notes' => $b['service_area_notes'],
        'service_area_cities' => $b['service_area_cities'] ?? null,
        'operating_hours' => $b['operating_hours'],
        'cutoff_time' => $b['cutoff_time'] === null ? null : substr((string) $b['cutoff_time'], 0, 5),
        'max_order_qty' => (int) $b['max_order_qty'],
        'rush_fee' => (float) ($b['rush_fee'] ?? 0),
        'outside_zone_fee' => (float) ($b['outside_zone_fee'] ?? 0),
        'branch_admin_id' => empty($b['branch_admin_id']) ? null : (int) $b['branch_admin_id'],
        'branch_admin' => $admin,
        'is_active' => (int) $b['is_active'],
        'created_at' => $b['created_at'] ?? null,
    ], address_payload($b));
}

function branch_code(string $raw, ?int $exceptId = null): string
{
    $code = strtoupper(trim($raw));
    if (!preg_match('/^[A-Z0-9_-]{2,20}$/', $code)) {
        fail('Branch code must be 2-20 letters, numbers, hyphens or underscores.', 422, 'validation_error', ['field' => 'code']);
    }
    $dup = row('SELECT id FROM branches WHERE code=? AND id<>?', [$code, $exceptId ?? 0]);
    if ($dup) {
        fail('That branch code is already in use.', 409, 'duplicate');
    }
    return $code;
}

function branch_time(?string $value): ?string
{
    if ($value === null || trim($value) === '') {
        return null;
    }
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value)) {
        fail('Cut-off time must use HH:MM (24-hour).', 422, 'validation_error', ['field' => 'cutoff_time']);
    }
    return $value . ':00';
}

/** Service-area cities may be sent as an array or a comma-separated string. */
function branch_cities_input(): ?string
{
    $v = body_value('service_area_cities');
    if ($v === null) {
        return null;
    }
    if (is_array($v)) {
        $v = implode(',', array_map('strval', $v));
    }
    if (!is_string($v) && !is_numeric($v)) {
        fail('Invalid service area cities value.', 422, 'validation_error', ['field' => 'service_area_cities']);
    }
    $v = trim((string) $v);
    return $v === '' ? null : mb_substr($v, 0, 500);
}

function branch_input(): array
{
    return [
        'name' => input_str('name', 'Branch name', 2, 100),
        'address' => address_input('', 'Address', false),
        'contact_phone' => input_str('contact_phone', 'Contact number', 0, 40, false),
        'gcash_number' => query_body_str('gcash_number', 80),
        'gcash_account_name' => query_body_str('gcash_account_name', 100),
        'maya_number' => query_body_str('maya_number', 80),
        'maya_account_name' => query_body_str('maya_account_name', 100),
        'service_area_notes' => input_str('service_area_notes', 'Service area notes', 0, 500, false),
        'service_area_cities' => branch_cities_input(),
        'operating_hours' => input_str('operating_hours', 'Operating hours', 0, 200, false),
        'cutoff_time' => branch_time(query_body_str('cutoff_time', 10)),
        'max_order_qty' => input_int('max_order_qty', 'Maximum cylinders per order', 1, 500, false) ?? 20,
        'rush_fee' => input_decimal('rush_fee', 'Rush order fee', 0, 100000, false),
        'outside_zone_fee' => input_decimal('outside_zone_fee', 'Outside-zone fee', 0, 100000, false),
    ];
}

/** Read a string field from the JSON body without requiring it. */
function query_body_str(string $key, int $max): ?string
{
    $v = body_value($key);
    if ($v === null) {
        return null;
    }
    if (!is_string($v) && !is_numeric($v)) {
        fail('Invalid ' . $key . ' value.', 422, 'validation_error', ['field' => $key]);
    }
    $v = trim((string) $v);
    return mb_substr($v, 0, $max);
}

function api_branches_list(): void
{
    require_owner();
    $branches = rows('SELECT * FROM branches ORDER BY is_active DESC, name');
    $today = today();
    $out = [];
    foreach ($branches as $b) {
        $id = (int) $b['id'];
        $payload = branch_payload($b);
        $payload['staff_count'] = (int) val(
            "SELECT COUNT(*) FROM users WHERE branch_id=? AND role IN ('branch_admin','staff','delivery') AND is_active=1",
            [$id],
            0
        );
        $payload['open_orders'] = (int) val(
            "SELECT COUNT(*) FROM orders WHERE branch_id=? AND status IN ('pending','approved')",
            [$id],
            0
        );
        $payload['open_transfers'] = (int) val(
            "SELECT COUNT(*) FROM stock_transfers
             WHERE (from_branch_id=? OR to_branch_id=?) AND status IN ('requested','approved','in_transit')",
            [$id, $id],
            0
        );
        $payload['sales_today'] = money(val(
            'SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE branch_id=? AND sale_date=? AND is_void=0',
            [$id, $today],
            0
        ));
        $payload['stock_full'] = (int) val(
            'SELECT COALESCE(SUM(full_change),0) FROM stock_movements WHERE branch_id=?',
            [$id],
            0
        );
        $payload['stock_empty'] = (int) val(
            'SELECT COALESCE(SUM(empty_change),0) FROM stock_movements WHERE branch_id=?',
            [$id],
            0
        );
        $out[] = $payload;
    }
    json_response($out);
}

function api_branches_create(): void
{
    $u = require_owner();
    $data = branch_input();
    $code = branch_code(query_body_str('code', 20) ?? '');

    $id = tx(function () use ($u, $data, $code) {
        $a = $data['address'];
        q(
            'INSERT INTO branches (name, code, address, street, barangay, city, province, region, postal_code,
                latitude, longitude, contact_phone, gcash_number, gcash_account_name, maya_number,
                maya_account_name, service_area_notes, service_area_cities, operating_hours, cutoff_time,
                max_order_qty, rush_fee, outside_zone_fee, is_active, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?)',
            [
                $data['name'], $code, $a['address'], $a['street'], $a['barangay'], $a['city'],
                $a['province'], $a['region'], $a['postal_code'], $a['latitude'], $a['longitude'],
                $data['contact_phone'] === '' ? null : $data['contact_phone'],
                $data['gcash_number'] === '' || $data['gcash_number'] === null ? null : $data['gcash_number'],
                $data['gcash_account_name'] === '' || $data['gcash_account_name'] === null ? null : $data['gcash_account_name'],
                $data['maya_number'] === '' || $data['maya_number'] === null ? null : $data['maya_number'],
                $data['maya_account_name'] === '' || $data['maya_account_name'] === null ? null : $data['maya_account_name'],
                $data['service_area_notes'] === '' ? null : $data['service_area_notes'],
                $data['service_area_cities'],
                $data['operating_hours'] === '' ? null : $data['operating_hours'],
                $data['cutoff_time'], $data['max_order_qty'],
                $data['rush_fee'] ?? BRANCH_DEFAULT_RUSH_FEE, $data['outside_zone_fee'] ?? 0, (int) $u['id'],
            ]
        );
        $id = (int) db()->lastInsertId();
        // Every branch must have one lock row per size so FOR UPDATE always works.
        q('INSERT IGNORE INTO stock_locks (branch_id, size_id) SELECT ?, id FROM cylinder_sizes', [$id]);
        return $id;
    });

    audit($u, 'create', 'branch', $id, null, ['name' => $data['name'], 'code' => $code], $id);
    $b = branch_row($id);
    json_response(branch_payload($b), 201);
}

function api_branches_update(array $params): void
{
    $u = require_owner();
    $id = (int) $params[0];
    $b = branch_row($id);
    if (!$b) {
        fail('Branch not found.', 404, 'not_found');
    }
    $data = branch_input();
    $code = branch_code(query_body_str('code', 20) ?? (string) $b['code'], $id);

    // Patch semantics for address and fees: keep stored values when omitted.
    $a = address_provided()
        ? $data['address']
        : [
            'address' => $b['address'], 'street' => $b['street'], 'barangay' => $b['barangay'],
            'city' => $b['city'], 'province' => $b['province'], 'region' => $b['region'],
            'postal_code' => $b['postal_code'], 'latitude' => $b['latitude'], 'longitude' => $b['longitude'],
        ];
    $rushFee = $data['rush_fee'] !== null ? $data['rush_fee'] : (float) ($b['rush_fee'] ?? 0);
    $outsideFee = $data['outside_zone_fee'] !== null ? $data['outside_zone_fee'] : (float) ($b['outside_zone_fee'] ?? 0);
    $optional = static function (string $key, array $branch, string $column) use ($data) {
        $v = $data[$key] ?? null;
        if ($v === null) {
            return $branch[$column] ?? null;
        }
        return $v === '' ? null : $v;
    };
    $gcashNumber = $optional('gcash_number', $b, 'gcash_number');
    $gcashName = $optional('gcash_account_name', $b, 'gcash_account_name');
    $mayaNumber = $optional('maya_number', $b, 'maya_number');
    $mayaName = $optional('maya_account_name', $b, 'maya_account_name');
    $cities = $data['service_area_cities'] === null ? ($b['service_area_cities'] ?? null) : $data['service_area_cities'];

    q(
        'UPDATE branches SET name=?, code=?, address=?, street=?, barangay=?, city=?, province=?, region=?,
            postal_code=?, latitude=?, longitude=?, contact_phone=?, gcash_number=?, gcash_account_name=?,
            maya_number=?, maya_account_name=?, service_area_notes=?, service_area_cities=?,
            operating_hours=?, cutoff_time=?, max_order_qty=?, rush_fee=?, outside_zone_fee=? WHERE id=?',
        [
            $data['name'], $code, $a['address'], $a['street'], $a['barangay'], $a['city'],
            $a['province'], $a['region'], $a['postal_code'], $a['latitude'], $a['longitude'],
            $data['contact_phone'] === '' ? null : $data['contact_phone'],
            $gcashNumber, $gcashName, $mayaNumber, $mayaName,
            $data['service_area_notes'] === '' ? null : $data['service_area_notes'], $cities,
            $data['operating_hours'] === '' ? null : $data['operating_hours'],
            $data['cutoff_time'], $data['max_order_qty'], $rushFee, $outsideFee, $id,
        ]
    );
    audit($u, 'update', 'branch', $id,
        ['name' => $b['name'], 'code' => $b['code'], 'cutoff_time' => $b['cutoff_time'], 'max_order_qty' => (int) $b['max_order_qty']],
        $data,
        $id
    );
    json_response(branch_payload(branch_row($id)));
}

function api_branches_deactivate(array $params): void
{
    $u = require_owner();
    $id = (int) $params[0];

    $b = tx(function () use ($id) {
        $b = row('SELECT * FROM branches WHERE id=? FOR UPDATE', [$id]);
        if (!$b) {
            fail('Branch not found.', 404, 'not_found');
        }
        if ((int) $b['is_active'] !== 1) {
            return $b; // already inactive: idempotent
        }
        $activeBranches = (int) val('SELECT COUNT(*) FROM branches WHERE is_active=1', [], 0);
        if ($activeBranches <= 1) {
            fail('The last active branch cannot be deactivated.', 409, 'last_branch');
        }

        $work = branch_has_open_work($id);
        if ($work['open_orders'] > 0) {
            fail('This branch has ' . $work['open_orders'] . ' open order(s). Resolve them first.', 409, 'branch_has_open_work', ['open_orders' => $work['open_orders']]);
        }
        if ($work['open_transfers'] > 0) {
            fail('This branch is part of ' . $work['open_transfers'] . ' open transfer(s). Resolve them first.', 409, 'branch_has_open_work', ['open_transfers' => $work['open_transfers']]);
        }
        if (!empty($work['stock'])) {
            $parts = array_map(static fn($s) => size_label((int) $s['size_id']) . ': ' . (int) $s['full_qty'] . ' full / ' . (int) $s['empty_qty'] . ' empty', $work['stock']);
            fail('This branch still has stock. Transfer or adjust it to zero first.', 409, 'branch_has_stock', ['stock' => $parts]);
        }
        $team = (int) val(
            "SELECT COUNT(*) FROM users WHERE branch_id=? AND role IN ('branch_admin','staff','delivery') AND is_active=1",
            [$id],
            0
        );
        if ($team > 0) {
            fail('Reassign the ' . $team . ' active branch team member(s) before deactivating this branch.', 409, 'branch_has_staff', ['staff' => $team]);
        }

        q('UPDATE branches SET is_active=0 WHERE id=?', [$id]);
        return $b;
    });

    audit($u, 'deactivate', 'branch', $id, ['is_active' => 1], ['is_active' => 0], $id);
    json_response(branch_payload(branch_row($id)));
}

function api_branches_activate(array $params): void
{
    $u = require_owner();
    $id = (int) $params[0];
    $b = branch_row($id);
    if (!$b) {
        fail('Branch not found.', 404, 'not_found');
    }
    q('UPDATE branches SET is_active=1 WHERE id=?', [$id]);
    q('INSERT IGNORE INTO stock_locks (branch_id, size_id) SELECT ?, id FROM cylinder_sizes', [$id]);
    audit($u, 'activate', 'branch', $id, ['is_active' => (int) $b['is_active']], ['is_active' => 1], $id);
    json_response(branch_payload(branch_row($id)));
}

function api_branch_prices_get(array $params): void
{
    $u = require_owner();
    $id = (int) $params[0];
    $b = require_active_branch($id);
    $sizes = rows('SELECT id, label, selling_price, valve_type, is_active FROM cylinder_sizes ORDER BY id');
    $prices = [];
    foreach (rows('SELECT size_id, price FROM branch_prices WHERE branch_id=?', [$id]) as $p) {
        $prices[(int) $p['size_id']] = (float) $p['price'];
    }
    $out = [];
    foreach ($sizes as $s) {
        $sid = (int) $s['id'];
        $out[] = [
            'size_id' => $sid,
            'label' => $s['label'],
            'valve_type' => $s['valve_type'],
            'is_active' => (int) $s['is_active'],
            'global_price' => (float) $s['selling_price'],
            'price' => $prices[$sid] ?? (float) $s['selling_price'],
            'overridden' => array_key_exists($sid, $prices),
        ];
    }
    json_response([
        'branch' => branch_payload($b),
        'prices' => $out,
    ]);
}

function api_branch_prices_put(array $params): void
{
    $u = require_owner();
    $id = (int) $params[0];
    require_active_branch($id);
    $raw = body_value('prices');
    if (!is_array($raw) || count($raw) === 0) {
        fail('Provide at least one price.', 422, 'validation_error', ['field' => 'prices']);
    }
    if (count($raw) > 100) {
        fail('Too many price rows.', 422, 'validation_error', ['field' => 'prices']);
    }

    $clean = [];
    foreach ($raw as $p) {
        if (!is_array($p)) {
            fail('Invalid price row.', 422, 'validation_error', ['field' => 'prices']);
        }
        $sid = isset($p['size_id']) && is_numeric($p['size_id']) ? (int) $p['size_id'] : 0;
        if ($sid < 1 || !row('SELECT id FROM cylinder_sizes WHERE id=?', [$sid])) {
            fail('Unknown cylinder size in price list.', 422, 'validation_error', ['field' => 'prices']);
        }
        if (!isset($p['price']) || !is_numeric($p['price']) || (float) $p['price'] < 0 || (float) $p['price'] > 1000000) {
            fail('Each price must be between 0 and 1,000,000.', 422, 'validation_error', ['field' => 'prices']);
        }
        if (isset($clean[$sid])) {
            fail('Each size may only appear once.', 422, 'validation_error', ['field' => 'prices']);
        }
        $clean[$sid] = round((float) $p['price'], 2);
    }

    $before = [];
    foreach (rows('SELECT size_id, price FROM branch_prices WHERE branch_id=?', [$id]) as $p) {
        $before[(int) $p['size_id']] = (float) $p['price'];
    }
    tx(function () use ($id, $u, $clean) {
        foreach ($clean as $sid => $price) {
            q(
                'INSERT INTO branch_prices (branch_id, size_id, price, updated_by) VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE price=VALUES(price), updated_by=VALUES(updated_by)',
                [$id, $sid, $price, (int) $u['id']]
            );
        }
    });
    audit($u, 'update', 'branch_prices', $id, ['prices' => $before], ['prices' => $clean], $id);
    json_response(['ok' => 1, 'updated' => count($clean)]);
}


/** Branch team: admin, staff and delivery riders for one branch. */
function api_branch_team(array $params): void
{
    $u = require_auth();
    $id = (int) $params[0];
    $b = require_branch_access($u, $id);
    $members = rows(
        "SELECT id, name, first_name, middle_name, last_name, name_suffix, username, role,
                phone, is_active, last_login_at
         FROM users
         WHERE branch_id=? AND role IN ('branch_admin','staff','delivery')
         ORDER BY FIELD(role,'branch_admin','staff','delivery'), name",
        [$id]
    );
    foreach ($members as &$m) {
        $m['id'] = (int) $m['id'];
        $m['is_active'] = (int) $m['is_active'];
        $m['is_rider'] = $m['role'] === 'delivery';
    }
    unset($m);
    json_response([
        'branch' => branch_payload($b),
        'members' => $members,
    ]);
}

/**
 * Assign or create the branch admin. The owner or this branch's own admin can
 * do it. The previous admin is demoted to staff so a branch never has two
 * linked admins. Owners are rejected.
 */
function api_branch_set_admin(array $params): void
{
    $id = (int) $params[0];
    $u = require_branch_manager($id);
    require_active_branch($id);
    $userId = input_int('user_id', 'User', 1, null, false);

    $result = tx(function () use ($id, $userId, $u) {
        $branch = row('SELECT * FROM branches WHERE id=? FOR UPDATE', [$id]);
        if (!$branch) {
            fail('Branch not found.', 404, 'not_found');
        }
        $previous = empty($branch['branch_admin_id']) ? null : (int) $branch['branch_admin_id'];
        $temporary = null;

        if ($userId !== null) {
            $target = row('SELECT * FROM users WHERE id=?', [$userId]);
            if (!$target) {
                fail('User not found.', 404, 'not_found');
            }
            if ($target['role'] === 'owner') {
                fail('The owner cannot be a branch admin.', 409, 'invalid_role_change');
            }
            if ((int) $target['is_active'] !== 1) {
                fail('Activate the user before making them a branch admin.', 409, 'user_inactive');
            }
            if ($u['role'] !== 'owner' && (int) ($target['branch_id'] ?? 0) !== $id) {
                fail('You can only choose a team member from your own branch.', 403, 'branch_forbidden');
            }
            // Drop any stale link from another branch before re-pointing this user.
            q('UPDATE branches SET branch_admin_id=NULL WHERE branch_admin_id=? AND id<>?', [$userId, $id]);
            // Re-saving the same admin must not revoke their own session.
            $alreadyAdmin = $target['role'] === 'branch_admin' && (int) ($target['branch_id'] ?? 0) === $id;
            q(
                'UPDATE users SET role=?, branch_id=?, session_version=session_version+? WHERE id=?',
                ['branch_admin', $id, $alreadyAdmin ? 0 : 1, $userId]
            );
            $adminId = $userId;
        } else {
            $person = person_name_input();
            $username = input_str('username', 'Username', 3, 30, true, '/^[A-Za-z0-9_.-]+$/');
            $password = (string) body_value('password', '');
            $min = (int) config('security.password_min_length', 8);
            if (strlen($password) < $min) {
                fail('Password must be at least ' . $min . ' characters.', 422, 'validation_error', ['field' => 'password']);
            }
            $contact = input_str('contact', 'Contact number', 0, 50, false);
            if (val('SELECT id FROM users WHERE username=?', [$username])) {
                fail('That username is already taken.', 409, 'username_taken');
            }
            q(
                "INSERT INTO users (name, first_name, middle_name, last_name, name_suffix,
                    username, password_hash, role, branch_id, phone, must_change_password)
                 VALUES (?,?,?,?,?,?,?,'branch_admin',?,?,0)",
                [
                    $person['name'], $person['first_name'], $person['middle_name'], $person['last_name'], $person['name_suffix'],
                    $username, hash_password($password), $id, $contact === '' ? null : $contact,
                ]
            );
            $adminId = (int) db()->lastInsertId();
        }

        if ($previous !== null && $previous !== $adminId) {
            q(
                "UPDATE users SET role='staff', session_version=session_version+1
                 WHERE id=? AND role='branch_admin'",
                [$previous]
            );
        }
        q('UPDATE branches SET branch_admin_id=? WHERE id=?', [$adminId, $id]);
        return ['admin_id' => $adminId, 'previous' => $previous, 'temporary_password' => $temporary];
    });

    audit($u, 'set_admin', 'branch', $id, ['branch_admin_id' => $result['previous']], ['branch_admin_id' => $result['admin_id']], $id);
    $payload = branch_payload(branch_row($id));
    $payload['temporary_password'] = $result['temporary_password'];
    json_response($payload);
}
