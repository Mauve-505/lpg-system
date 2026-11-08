<?php
declare(strict_types=1);

/**
 * Branch administration (owner only) and per-branch price lists.
 */

function branch_payload(array $b): array
{
    return [
        'id' => (int) $b['id'],
        'name' => $b['name'],
        'code' => $b['code'],
        'address' => $b['address'],
        'contact_phone' => $b['contact_phone'],
        'service_area_notes' => $b['service_area_notes'],
        'operating_hours' => $b['operating_hours'],
        'cutoff_time' => $b['cutoff_time'] === null ? null : substr((string) $b['cutoff_time'], 0, 5),
        'max_order_qty' => (int) $b['max_order_qty'],
        'is_active' => (int) $b['is_active'],
        'created_at' => $b['created_at'] ?? null,
    ];
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

function branch_input(): array
{
    return [
        'name' => input_str('name', 'Branch name', 2, 100),
        'address' => input_str('address', 'Address', 0, 200, false),
        'contact_phone' => input_str('contact_phone', 'Contact number', 0, 40, false),
        'service_area_notes' => input_str('service_area_notes', 'Service area notes', 0, 500, false),
        'operating_hours' => input_str('operating_hours', 'Operating hours', 0, 200, false),
        'cutoff_time' => branch_time(query_body_str('cutoff_time', 10)),
        'max_order_qty' => input_int('max_order_qty', 'Maximum cylinders per order', 1, 500, false) ?? 20,
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
            "SELECT COUNT(*) FROM users WHERE branch_id=? AND role='staff' AND is_active=1",
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
        q(
            'INSERT INTO branches (name, code, address, contact_phone, service_area_notes, operating_hours,
                cutoff_time, max_order_qty, is_active, created_by)
             VALUES (?,?,?,?,?,?,?,?,1,?)',
            [
                $data['name'], $code,
                $data['address'] === '' ? null : $data['address'],
                $data['contact_phone'] === '' ? null : $data['contact_phone'],
                $data['service_area_notes'] === '' ? null : $data['service_area_notes'],
                $data['operating_hours'] === '' ? null : $data['operating_hours'],
                $data['cutoff_time'], $data['max_order_qty'], (int) $u['id'],
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

    q(
        'UPDATE branches SET name=?, code=?, address=?, contact_phone=?, service_area_notes=?,
            operating_hours=?, cutoff_time=?, max_order_qty=? WHERE id=?',
        [
            $data['name'], $code,
            $data['address'] === '' ? null : $data['address'],
            $data['contact_phone'] === '' ? null : $data['contact_phone'],
            $data['service_area_notes'] === '' ? null : $data['service_area_notes'],
            $data['operating_hours'] === '' ? null : $data['operating_hours'],
            $data['cutoff_time'], $data['max_order_qty'], $id,
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
        $staff = (int) val("SELECT COUNT(*) FROM users WHERE branch_id=? AND role='staff' AND is_active=1", [$id], 0);
        if ($staff > 0) {
            fail('Reassign the ' . $staff . ' active staff member(s) before deactivating this branch.', 409, 'branch_has_staff', ['staff' => $staff]);
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
