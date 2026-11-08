<?php
declare(strict_types=1);

function delivery_payload(array $d, array $items): array
{
    $first = $items[0] ?? null;
    return [
        'id' => (int) $d['id'],
        'branch_id' => (int) $d['branch_id'],
        'branch_name' => $d['branch_name'] ?? null,
        'delivery_date' => $d['delivery_date'],
        'receipt_no' => $d['receipt_no'],
        'supplier' => $d['supplier'],
        'remarks' => $d['remarks'],
        'received_by' => $d['received_by_name'] ?? null,
        'is_void' => (int) $d['is_void'],
        'void_reason' => $d['void_reason'],
        'items' => $items,
        // Legacy single-line fields.
        'size' => $first['size'] ?? null,
        'full_received' => array_sum(array_column($items, 'full_received')),
        'empties_returned' => array_sum(array_column($items, 'empties_returned')),
    ];
}

function api_deliveries_list(): void
{
    $u = require_staff_or_owner();
    $scope = branch_scope($u);
    [$sc, $sp] = scope_sql($scope, 'd.branch_id');
    $p = pagination();
    $where = '1=1' . $sc;
    $params = $sp;
    if (($from = query_str('from', 10)) !== '') {
        $where .= ' AND d.delivery_date>=?';
        $params[] = $from;
    }
    if (($to = query_str('to', 10)) !== '') {
        $where .= ' AND d.delivery_date<=?';
        $params[] = $to;
    }
    if ($p['q'] !== '') {
        $where .= ' AND (d.receipt_no LIKE ? OR d.supplier LIKE ?)';
        $like = '%' . $p['q'] . '%';
        $params[] = $like;
        $params[] = $like;
    }
    $total = (int) val('SELECT COUNT(*) FROM plant_deliveries d WHERE ' . $where, $params, 0);
    $st = db()->prepare(
        'SELECT d.*, b.name branch_name, u.name received_by_name
         FROM plant_deliveries d JOIN branches b ON b.id=d.branch_id JOIN users u ON u.id=d.received_by
         WHERE ' . $where . ' ORDER BY d.delivery_date DESC, d.id DESC LIMIT ? OFFSET ?'
    );
    bind_page($st, $params, $p['per'], $p['offset']);
    $st->execute();
    $deliveries = $st->fetchAll();
    $ids = array_map(static fn($d) => (int) $d['id'], $deliveries);
    $items = [];
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $r = rows(
            "SELECT i.*, z.label AS size FROM plant_delivery_items i JOIN cylinder_sizes z ON z.id=i.size_id
             WHERE i.delivery_id IN ($in) ORDER BY i.id",
            $ids
        );
        foreach ($r as $x) {
            $x['size_id'] = (int) $x['size_id'];
            $x['full_received'] = (int) $x['full_received'];
            $x['empties_returned'] = (int) $x['empties_returned'];
            $items[(int) $x['delivery_id']][] = $x;
        }
    }
    paginate_headers($total, $p['page'], $p['per']);
    json_response(array_map(static fn($d) => delivery_payload($d, $items[(int) $d['id']] ?? []), $deliveries));
}

function api_deliveries_create(): void
{
    $u = require_staff_or_owner();
    $scope = branch_scope($u, true);
    $branchId = (int) $scope['id'];
    require_active_branch($branchId);

    $date = input_date('date', 'Delivery date', false) ?: today();
    $receipt = input_str('receipt', 'Receipt number', 1, 50);
    $supplier = input_str('supplier', 'Supplier', 0, 120, false);
    $remarks = input_str('remarks', 'Remarks', 0, 200, false);

    $items = body_value('items');
    if (!is_array($items) || count($items) === 0) {
        fail('Add at least one cylinder size.', 422, 'validation_error', ['field' => 'items']);
    }
    if (count($items) > 20) {
        fail('A delivery can have at most 20 line items.', 422, 'validation_error', ['field' => 'items']);
    }
    $clean = [];
    $seen = [];
    foreach ($items as $it) {
        if (!is_array($it)) {
            fail('Invalid delivery item.', 422, 'validation_error', ['field' => 'items']);
        }
        $sid = isset($it['size_id']) && is_numeric($it['size_id']) ? (int) $it['size_id'] : 0;
        $full = isset($it['full']) && is_numeric($it['full']) ? (float) $it['full'] : 0.0;
        $empty = isset($it['empties']) && is_numeric($it['empties']) ? (float) $it['empties'] : 0.0;
        if ($sid < 1 || !row('SELECT id FROM cylinder_sizes WHERE id=?', [$sid])) {
            fail('Unknown cylinder size.', 422, 'validation_error', ['field' => 'items']);
        }
        if (floor($full) !== $full || $full < 0 || $full > 100000 || floor($empty) !== $empty || $empty < 0 || $empty > 100000) {
            fail('Quantities must be whole numbers between 0 and 100000.', 422, 'validation_error', ['field' => 'items']);
        }
        if ($full + $empty <= 0) {
            fail('Each line needs a full or empty quantity.', 422, 'validation_error', ['field' => 'items']);
        }
        if (isset($seen[$sid])) {
            fail('Each size may only appear once per delivery.', 422, 'validation_error', ['field' => 'items']);
        }
        $seen[$sid] = true;
        $clean[] = ['size_id' => $sid, 'full' => (int) $full, 'empties' => (int) $empty];
    }

    $id = tx(function () use ($u, $branchId, $date, $receipt, $supplier, $remarks, $clean) {
        lock_stock($branchId, array_map(static fn($x) => $x['size_id'], $clean));
        // Recording empties returned to the plant cannot take empties below zero.
        foreach ($clean as $it) {
            assert_stock_after($branchId, $it['size_id'], $it['full'], -$it['empties'], 0);
        }
        q(
            'INSERT INTO plant_deliveries (branch_id, delivery_date, receipt_no, supplier, remarks, received_by)
             VALUES (?,?,?,?,?,?)',
            [$branchId, $date, $receipt, $supplier === '' ? null : $supplier, $remarks === '' ? null : $remarks, (int) $u['id']]
        );
        $id = (int) db()->lastInsertId();
        foreach ($clean as $it) {
            q(
                'INSERT INTO plant_delivery_items (delivery_id, size_id, full_received, empties_returned) VALUES (?,?,?,?)',
                [$id, $it['size_id'], $it['full'], $it['empties']]
            );
            movement($branchId, $date, $it['size_id'], 'delivery', $id, $it['full'], -$it['empties'], 0, (int) $u['id'], 'Plant delivery ' . $receipt);
        }
        return $id;
    });

    audit($u, 'create', 'delivery', $id, null, ['receipt_no' => $receipt, 'branch_id' => $branchId], $branchId);
    json_response(['ok' => 1, 'id' => $id], 201);
}

function api_deliveries_void(array $params): void
{
    $u = require_owner();
    $id = (int) $params[0];
    $reason = input_str('reason', 'Void reason', 3, 200, true);
    $branchId = tx(function () use ($u, $id, $reason) {
        $d = row('SELECT * FROM plant_deliveries WHERE id=? FOR UPDATE', [$id]);
        if (!$d) {
            fail('Delivery not found.', 404, 'not_found');
        }
        if ((int) $d['is_void'] === 1) {
            fail('This delivery is already void.', 409, 'already_void');
        }
        $items = rows('SELECT * FROM plant_delivery_items WHERE delivery_id=?', [$id]);
        lock_stock((int) $d['branch_id'], array_map(static fn($x) => (int) $x['size_id'], $items));
        foreach ($items as $it) {
            // Reverse: remove the full cylinders that came in, give back the empties that went out.
            assert_stock_after((int) $d['branch_id'], (int) $it['size_id'], -(int) $it['full_received'], (int) $it['empties_returned'], 0);
        }
        foreach ($items as $it) {
            movement((int) $d['branch_id'], today(), (int) $it['size_id'], 'void', $id, -(int) $it['full_received'], (int) $it['empties_returned'], 0, (int) $u['id'], 'Void delivery: ' . $reason);
        }
        q('UPDATE plant_deliveries SET is_void=1, voided_at=?, voided_by=?, void_reason=? WHERE id=?', [now_iso(), (int) $u['id'], $reason, $id]);
        return (int) $d['branch_id'];
    });
    audit($u, 'void', 'delivery', $id, ['is_void' => 0], ['is_void' => 1, 'reason' => $reason], $branchId);
    ok(['ok' => 1, 'voided' => true]);
}
