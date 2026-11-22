<?php
declare(strict_types=1);

function order_payload(array $o): array
{
    $items = rows(
        'SELECT oi.id, oi.size_id, oi.qty, oi.unit_price, oi.deposit_amount, oi.line_total, z.label AS size
         FROM order_items oi JOIN cylinder_sizes z ON z.id=oi.size_id
         WHERE oi.order_id=? ORDER BY oi.id',
        [(int) $o['id']]
    );
    foreach ($items as &$it) {
        $it['size_id'] = (int) $it['size_id'];
        $it['qty'] = (int) $it['qty'];
        $it['unit_price'] = (float) $it['unit_price'];
        $it['deposit_amount'] = (float) $it['deposit_amount'];
        $it['line_total'] = (float) $it['line_total'];
    }
    unset($it);

    $qtyTotal = array_sum(array_column($items, 'qty'));
    $first = $items[0] ?? null;

    return array_merge([
        'id' => (int) $o['id'],
        'order_no' => $o['order_no'] ?? null,
        'branch_id' => (int) $o['branch_id'],
        'branch_name' => $o['branch_name'] ?? null,
        'customer_id' => isset($o['customer_id']) ? (int) $o['customer_id'] : null,
        'customer' => $o['customer'] ?? null,
        'customer_contact' => $o['customer_contact'] ?? null,
        'order_date' => $o['order_date'],
        'requested_date' => $o['requested_date'] ?? null,
        'time_slot' => $o['time_slot'] ?? null,
        'delivery_address' => $o['delivery_address'] ?? null,
        'valve_type' => $o['valve_type'] ?? 'na',
        'payment_type' => $o['payment_type'],
        'notes' => $o['notes'],
        'status' => $o['status'],
        'subtotal' => (float) $o['subtotal'],
        'deposit_total' => (float) $o['deposit_total'],
        'is_rush' => (int) ($o['is_rush'] ?? 0),
        'rush_fee' => (float) ($o['rush_fee'] ?? 0),
        'is_outside_zone' => (int) ($o['is_outside_zone'] ?? 0),
        'outside_zone_fee' => (float) ($o['outside_zone_fee'] ?? 0),
        'total_amount' => (float) $o['total_amount'],
        'sale_id' => $o['sale_id'] === null ? null : (int) $o['sale_id'],
        'rider_id' => $o['rider_id'] === null ? null : (int) $o['rider_id'],
        'rider_name' => $o['rider_name'] ?? null,
        'proof_of_delivery' => $o['proof_of_delivery'],
        'delivered_at' => $o['delivered_at'],
        'created_at' => $o['created_at'],
        'items' => $items,
        // Legacy single-size fields for older clients.
        'size' => $first ? $first['size'] : null,
        'qty' => $qtyTotal,
    ], address_payload($o, 'delivery_'));
}

function load_order_for_user(array $u, int $id): array
{
    $o = row(
        'SELECT o.*, b.name branch_name, c.name customer, c.contact customer_contact, r.name rider_name
         FROM orders o JOIN branches b ON b.id=o.branch_id JOIN customers c ON c.id=o.customer_id
         LEFT JOIN users r ON r.id=o.rider_id
         WHERE o.id=?',
        [$id]
    );
    if (!$o) {
        fail('Order not found.', 404, 'not_found');
    }
    if (!can_access_branch($u, (int) $o['branch_id'])) {
        fail('You do not have access to that order.', 403, 'branch_forbidden');
    }
    return $o;
}

function api_orders_list(): void
{
    $u = require_order_access();
    $scope = branch_scope($u);
    [$sc, $sp] = scope_sql($scope, 'o.branch_id');
    $p = pagination();
    $where = '1=1' . $sc;
    $params = $sp;

    $status = query_str('status', 20);
    if ($status !== '') {
        if (!in_array($status, ['pending', 'approved', 'delivered', 'cancelled'], true)) {
            fail('Invalid status filter.', 422, 'validation_error', ['field' => 'status']);
        }
        $where .= ' AND o.status=?';
        $params[] = $status;
    }
    if ($p['q'] !== '') {
        $where .= ' AND (o.order_no LIKE ? OR c.name LIKE ? OR o.delivery_address LIKE ?)';
        $like = '%' . $p['q'] . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    $from = query_str('from', 10);
    $to = query_str('to', 10);
    if ($from !== '') {
        $where .= ' AND o.order_date>=?';
        $params[] = $from;
    }
    if ($to !== '') {
        $where .= ' AND o.order_date<=?';
        $params[] = $to;
    }

    $total = (int) val(
        'SELECT COUNT(*) FROM orders o JOIN customers c ON c.id=o.customer_id WHERE ' . $where,
        $params,
        0
    );
    $st = db()->prepare(
        'SELECT o.*, b.name branch_name, c.name customer, c.contact customer_contact, r.name rider_name
         FROM orders o JOIN branches b ON b.id=o.branch_id JOIN customers c ON c.id=o.customer_id
         LEFT JOIN users r ON r.id=o.rider_id
         WHERE ' . $where . " ORDER BY FIELD(o.status,'pending','approved','delivered','cancelled'), o.id DESC
         LIMIT ? OFFSET ?"
    );
    bind_page($st, $params, $p['per'], $p['offset']);
    $st->execute();
    paginate_headers($total, $p['page'], $p['per']);
    json_response(array_map('order_payload', $st->fetchAll()));
}

function api_order_show(array $params): void
{
    $u = require_order_access();
    $o = load_order_for_user($u, (int) $params[0]);
    json_response(order_payload($o));
}

function api_order_approve(array $params): void
{
    $u = require_staff_or_owner();
    $orderId = (int) $params[0];
    $branchId = tx(function () use ($orderId, $u) {
        $o = row('SELECT * FROM orders WHERE id=? FOR UPDATE', [$orderId]);
        if (!$o) {
            fail('Order not found.', 404, 'not_found');
        }
        require_branch_access($u, (int) $o['branch_id'], true);
        if ($o['status'] !== 'pending') {
            fail('Only pending orders can be approved.', 409, 'order_locked');
        }
        q("UPDATE orders SET status='approved' WHERE id=?", [$orderId]);
        return (int) $o['branch_id'];
    });
    audit($u, 'approve', 'order', $orderId, ['status' => 'pending'], ['status' => 'approved'], $branchId);
    ok();
}

function api_order_cancel(array $params): void
{
    $u = require_staff_or_owner();
    $orderId = (int) $params[0];
    $branchId = tx(function () use ($orderId, $u) {
        $o = row('SELECT * FROM orders WHERE id=? FOR UPDATE', [$orderId]);
        if (!$o) {
            fail('Order not found.', 404, 'not_found');
        }
        require_branch_access($u, (int) $o['branch_id'], true);
        if (!in_array($o['status'], ['pending', 'approved'], true)) {
            fail('This order can no longer be cancelled.', 409, 'order_locked');
        }
        q("UPDATE orders SET status='cancelled' WHERE id=?", [$orderId]);
        return (int) $o['branch_id'];
    });
    audit($u, 'cancel', 'order', $orderId, ['status' => 'pending_or_approved'], ['status' => 'cancelled'], $branchId);
    ok();
}

/** Map per-size empties received on delivery. */
function delivery_empties(array $items): array
{
    $map = [];
    foreach ($items as $it) {
        $map[(int) $it['size_id']] = 0;
    }
    $rawItems = body_value('items');
    if (is_array($rawItems)) {
        foreach ($rawItems as $x) {
            if (!is_array($x) || !isset($x['size_id'])) {
                continue;
            }
            $sid = (int) $x['size_id'];
            if (!array_key_exists($sid, $map)) {
                fail('An empties entry does not match this order.', 422, 'validation_error', ['field' => 'items']);
            }
            $n = isset($x['empties_received']) && is_numeric($x['empties_received']) ? (float) $x['empties_received'] : 0.0;
            if (floor($n) !== $n || $n < 0 || $n > 1000) {
                fail('Empties received must be a whole number between 0 and 1000.', 422, 'validation_error', ['field' => 'items']);
            }
            $map[$sid] = (int) $n;
        }
        return $map;
    }
    $rawMap = body_value('empties');
    if (is_array($rawMap)) {
        foreach ($rawMap as $sid => $n) {
            $sid = (int) $sid;
            if (!array_key_exists($sid, $map)) {
                fail('An empties entry does not match this order.', 422, 'validation_error', ['field' => 'empties']);
            }
            $n = (float) $n;
            if (floor($n) !== $n || $n < 0 || $n > 1000) {
                fail('Empties received must be a whole number between 0 and 1000.', 422, 'validation_error', ['field' => 'empties']);
            }
            $map[$sid] = (int) $n;
        }
        return $map;
    }
    $back = body_value('back');
    if ($back !== null && $back !== '') {
        $n = (float) $back;
        if (floor($n) !== $n || $n < 0 || $n > 1000) {
            fail('Empties received must be a whole number between 0 and 1000.', 422, 'validation_error', ['field' => 'back']);
        }
        // Legacy single-item UI: apply to the first line.
        $first = array_key_first($map);
        if ($first !== null) {
            $map[$first] = (int) $n;
        }
    }
    return $map;
}

/** Validate a rider that belongs to the order branch (owner may ride any). */
function order_rider_row(int $riderId, int $branchId): array
{
    $rider = row(
        "SELECT id, role, branch_id FROM users
         WHERE id=? AND is_active=1 AND role IN ('staff','branch_admin','delivery','owner')",
        [$riderId]
    );
    if (!$rider) {
        fail('Rider not found.', 422, 'validation_error', ['field' => 'rider_id']);
    }
    if ($rider['role'] !== 'owner' && (int) $rider['branch_id'] !== $branchId) {
        fail('Choose a rider from this order branch.', 422, 'validation_error', ['field' => 'rider_id']);
    }
    return $rider;
}

function api_order_deliver(array $params): void
{
    $u = require_order_access();
    $orderId = (int) $params[0];
    $proof = input_str('proof_of_delivery', 'Proof of delivery', 0, 255, false);
    $riderId = body_value('rider_id') !== null && body_value('rider_id') !== ''
        ? input_int('rider_id', 'Rider', 1)
        : null;

    $result = tx(function () use ($orderId, $u, $proof, $riderId) {
        // Lock order row first: a retried request cannot create a second sale.
        $o = row('SELECT * FROM orders WHERE id=? FOR UPDATE', [$orderId]);
        if (!$o) {
            fail('Order not found.', 404, 'not_found');
        }
        require_branch_access($u, (int) $o['branch_id'], true);
        if (!in_array($o['status'], ['pending', 'approved'], true)) {
            fail('Order cannot be delivered in its current state.', 409, 'order_locked');
        }
        $items = rows('SELECT oi.*, z.label FROM order_items oi JOIN cylinder_sizes z ON z.id=oi.size_id WHERE oi.order_id=?', [$orderId]);
        if (!$items) {
            fail('Order has no items.', 409, 'empty_order');
        }

        if ($u['role'] === 'delivery') {
            if ($riderId !== null && $riderId !== (int) $u['id']) {
                fail('Delivery riders can only deliver as themselves.', 403, 'forbidden');
            }
            $riderId = (int) $u['id'];
        }
        if ($riderId !== null) {
            order_rider_row($riderId, (int) $o['branch_id']);
        }

        $sizeIds = array_map(static fn($x) => (int) $x['size_id'], $items);
        lock_stock((int) $o['branch_id'], $sizeIds);
        $stock = stock_balances((int) $o['branch_id'], $sizeIds);
        foreach ($items as $it) {
            $sid = (int) $it['size_id'];
            if ($stock[$sid]['full_qty'] < (int) $it['qty']) {
                fail('Not enough ' . $it['label'] . ' full cylinders in this branch (available: ' . $stock[$sid]['full_qty'] . ').', 409, 'insufficient_stock');
            }
        }

        $empties = delivery_empties($items);
        foreach ($items as $it) {
            $sid = (int) $it['size_id'];
            $qty = (int) $it['qty'];
            $back = (int) ($empties[$sid] ?? 0);
            // Validate full, empty and out-with-customer buckets before writing.
            assert_stock_after((int) $o['branch_id'], $sid, -$qty, $back, $qty - $back);
        }
        $date = today();
        $subtotal = 0.0;
        $deposit = 0.0;
        foreach ($items as $it) {
            $subtotal += (int) $it['qty'] * (float) $it['unit_price'];
            $deposit += (float) $it['deposit_amount'];
        }
        $rushFee = money($o['rush_fee'] ?? 0);
        $outsideFee = money($o['outside_zone_fee'] ?? 0);
        $total = round($subtotal + $deposit + $rushFee + $outsideFee, 2);

        q(
            'INSERT INTO sales (branch_id, sale_date, customer_id, payment_type, subtotal, deposit_total,
                is_rush, rush_fee, is_outside_zone, outside_zone_fee, total_amount, order_id, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                (int) $o['branch_id'], $date, (int) $o['customer_id'], $o['payment_type'], $subtotal, $deposit,
                (int) ($o['is_rush'] ?? 0), $rushFee, (int) ($o['is_outside_zone'] ?? 0), $outsideFee,
                $total, $orderId, (int) $u['id'],
            ]
        );
        $saleId = (int) db()->lastInsertId();
        q('UPDATE sales SET sale_no=? WHERE id=?', [doc_no('SALE', $date, $saleId), $saleId]);

        foreach ($items as $it) {
            $sid = (int) $it['size_id'];
            $qty = (int) $it['qty'];
            $back = $empties[$sid] ?? 0;
            $line = round($qty * (float) $it['unit_price'] + (float) $it['deposit_amount'], 2);
            q(
                'INSERT INTO sale_items (sale_id, size_id, qty, unit_price, empties_received, deposit_amount, line_total)
                 VALUES (?,?,?,?,?,?,?)',
                [$saleId, $sid, $qty, $it['unit_price'], $back, $it['deposit_amount'], $line]
            );
            movement((int) $o['branch_id'], $date, $sid, 'sale', $saleId, -$qty, $back, $qty - $back, (int) $u['id'], 'Order ' . ($o['order_no'] ?? $orderId));
        }

        q(
            "UPDATE orders SET status='delivered', sale_id=?, delivered_at=?, rider_id=COALESCE(?,rider_id),
                proof_of_delivery=COALESCE(?,proof_of_delivery) WHERE id=?",
            [$saleId, now_iso(), $riderId, $proof === '' ? null : $proof, $orderId]
        );
        return ['sale_id' => $saleId, 'branch_id' => (int) $o['branch_id'], 'total' => $total, 'sale_no' => doc_no('SALE', $date, $saleId)];
    });

    audit($u, 'deliver', 'order', $orderId, ['status' => 'pending_or_approved'], ['status' => 'delivered', 'sale_id' => $result['sale_id']], $result['branch_id']);
    ok([
        'ok' => 1,
        'sale_id' => $result['sale_id'],
        'sale_no' => $result['sale_no'],
        'total_amount' => $result['total'],
    ]);
}

function api_order_assign(array $params): void
{
    $u = require_order_access();
    $orderId = (int) $params[0];
    $riderId = input_int('rider_id', 'Rider', 1, null, false);
    $o = load_order_for_user($u, $orderId);
    if (!in_array($o['status'], ['pending', 'approved'], true)) {
        fail('Only open orders can be assigned.', 409, 'order_locked');
    }
    if ($u['role'] === 'delivery') {
        if ($riderId !== null && $riderId !== (int) $u['id']) {
            fail('Delivery riders can only assign themselves.', 403, 'forbidden');
        }
        $riderId = (int) $u['id'];
    }
    if ($riderId !== null) {
        order_rider_row($riderId, (int) $o['branch_id']);
    }
    q('UPDATE orders SET rider_id=? WHERE id=?', [$riderId, $orderId]);
    audit($u, 'assign', 'order', $orderId, ['rider_id' => $o['rider_id']], ['rider_id' => $riderId], (int) $o['branch_id']);
    ok();
}
