<?php
declare(strict_types=1);

/** Parse and validate order items from the request body. */
function parse_order_items(): array
{
    $items = body_value('items');
    if (($items === null || $items === []) && body_value('size_id') !== null) {
        // Backwards-compatible single-item shape from the old React app.
        $items = [[
            'size_id' => body_value('size_id'),
            'qty' => body_value('qty'),
            'deposit_amount' => body_value('deposit_amount', 0),
        ]];
    }
    if (!is_array($items) || count($items) === 0) {
        fail('Add at least one cylinder size.', 422, 'validation_error', ['field' => 'items']);
    }
    if (count($items) > 10) {
        fail('An order can have at most 10 line items.', 422, 'validation_error', ['field' => 'items']);
    }

    $clean = [];
    $seen = [];
    foreach ($items as $i => $it) {
        if (!is_array($it)) {
            fail('Invalid item at line ' . ($i + 1) . '.', 422, 'validation_error', ['field' => 'items']);
        }
        $sizeId = isset($it['size_id']) && is_numeric($it['size_id']) ? (int) $it['size_id'] : 0;
        $qty = isset($it['qty']) && is_numeric($it['qty']) ? (float) $it['qty'] : 0;
        if ($sizeId < 1) {
            fail('Choose a cylinder size for every line.', 422, 'validation_error', ['field' => 'items']);
        }
        if (floor($qty) !== $qty || $qty < 1 || $qty > 200) {
            fail('Quantity must be a whole number between 1 and 200.', 422, 'validation_error', ['field' => 'items']);
        }
        if (isset($seen[$sizeId])) {
            fail('Each cylinder size may only appear once per order.', 422, 'validation_error', ['field' => 'items']);
        }
        $seen[$sizeId] = true;
        $deposit = isset($it['deposit_amount']) && is_numeric($it['deposit_amount']) ? (float) $it['deposit_amount'] : 0;
        if ($deposit < 0 || $deposit > 100000) {
            fail('Invalid deposit amount.', 422, 'validation_error', ['field' => 'items']);
        }
        $size = row('SELECT id, label, selling_price, valve_type FROM cylinder_sizes WHERE id=? AND is_active=1', [$sizeId]);
        if (!$size) {
            fail('One of the selected cylinder sizes is not available.', 422, 'validation_error', ['field' => 'items']);
        }
        $clean[] = [
            'size_id' => $sizeId,
            'label' => $size['label'],
            'qty' => (int) $qty,
            'deposit_amount' => round($deposit, 2),
            'valve_type' => $size['valve_type'],
        ];
    }
    return $clean;
}

/** Price items for a branch and calculate totals. Caller must be in a transaction. */
function price_order_items(int $branchId, array $items, string $paymentType, ?int $customerId, array $feeValues = []): array
{
    $subtotal = 0.0;
    $deposits = 0.0;
    foreach ($items as &$it) {
        $it['unit_price'] = resolve_price($branchId, (int) $it['size_id']);
        $it['line_total'] = round($it['qty'] * $it['unit_price'] + $it['deposit_amount'], 2);
        $subtotal += $it['qty'] * $it['unit_price'];
        $deposits += $it['deposit_amount'];
    }
    unset($it);

    $rushFee = money($feeValues['rush_fee'] ?? 0);
    $outsideFee = money($feeValues['outside_zone_fee'] ?? 0);
    $feeTotal = round($rushFee + $outsideFee, 2);

    if ($paymentType === 'credit' && $customerId !== null) {
        $cust = row('SELECT credit_limit FROM customers WHERE id=?', [$customerId]);
        if ($cust && $cust['credit_limit'] !== null) {
            $bal = customer_balance($customerId, $branchId);
            if ($bal['balance'] + $subtotal + $deposits + $feeTotal > (float) $cust['credit_limit']) {
                fail('This credit order would exceed the customer credit limit.', 409, 'credit_limit');
            }
        }
    }

    return [
        'items' => $items,
        'subtotal' => round($subtotal, 2),
        'deposit_total' => round($deposits, 2),
        'is_rush' => $feeValues['is_rush'] ?? 0,
        'rush_fee' => $rushFee,
        'is_outside_zone' => $feeValues['is_outside_zone'] ?? 0,
        'outside_zone_fee' => $outsideFee,
        'total_amount' => round($subtotal + $deposits + $feeTotal, 2),
    ];
}

function api_my_account(): void
{
    $u = require_customer();
    $c = row('SELECT * FROM customers WHERE id=?', [(int) $u['customer_id']]);
    if (!$c) {
        fail('Customer account not found.', 404, 'not_found');
    }
    $byBranch = customer_balances_by_branch((int) $u['customer_id']);
    $total = ['balance' => 0.0, 'empties_owed' => 0];
    foreach ($byBranch as $b) {
        $total['balance'] += $b['balance'];
        $total['empties_owed'] += $b['empties_owed'];
    }
    json_response(array_merge([
        'id' => (int) $c['id'],
        'name' => $c['name'],
        'contact' => $c['contact'],
        'address' => $c['address'],
        'credit_limit' => $c['credit_limit'] === null ? null : (float) $c['credit_limit'],
        'is_reseller' => (int) $c['is_reseller'],
        'reseller_status' => $c['reseller_status'],
        'balance' => round($total['balance'], 2),
        'empties_owed' => $total['empties_owed'],
        'by_branch' => $byBranch,
    ], person_name_payload($c), address_payload($c)));
}

function api_my_orders_list(): void
{
    $u = require_customer();
    $p = pagination();
    $where = 'o.customer_id=?';
    $params = [(int) $u['customer_id']];
    if ($p['q'] !== '') {
        $where .= ' AND (o.order_no LIKE ? OR o.notes LIKE ? OR o.delivery_address LIKE ?)';
        $like = '%' . $p['q'] . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    $total = (int) val('SELECT COUNT(*) FROM orders o WHERE ' . $where, $params, 0);
    $st = db()->prepare(
        'SELECT o.*, b.name branch_name, r.name rider_name FROM orders o JOIN branches b ON b.id=o.branch_id
         LEFT JOIN users r ON r.id=o.rider_id
         WHERE ' . $where . ' ORDER BY o.id DESC LIMIT ? OFFSET ?'
    );
    bind_page($st, $params, $p['per'], $p['offset']);
    $st->execute();
    $orders = $st->fetchAll();
    paginate_headers($total, $p['page'], $p['per']);
    json_response(array_map('order_payload', $orders));
}

function api_my_order_show(array $params): void
{
    $u = require_customer();
    $o = row('SELECT o.*, b.name branch_name, r.name rider_name FROM orders o JOIN branches b ON b.id=o.branch_id LEFT JOIN users r ON r.id=o.rider_id WHERE o.id=? AND o.customer_id=?', [$params[0], (int) $u['customer_id']]);
    if (!$o) {
        fail('Order not found.', 404, 'not_found');
    }
    json_response(order_payload($o));
}

function api_my_orders_create(): void
{
    $u = require_customer();
    $branchId = input_int('branch_id', 'Branch', 1);
    $branch = require_active_branch((int) $branchId);

    $items = parse_order_items();
    $totalQty = array_sum(array_column($items, 'qty'));
    if ($totalQty > (int) $branch['max_order_qty']) {
        fail('This branch accepts at most ' . (int) $branch['max_order_qty'] . ' cylinders per order.', 422, 'order_limit');
    }

    $payment = input_enum('pay', 'Payment type', ['cash', 'credit']);
    $notes = input_str('notes', 'Notes', 0, 200, false);
    $requested = input_date('requested_date', 'Requested delivery date', false) ?: today();
    $timeSlot = input_str('time_slot', 'Time slot', 0, 40, false);
    $cust = row(
        'SELECT address, street, barangay, city, province, region, postal_code, latitude, longitude
         FROM customers WHERE id=?',
        [(int) $u['customer_id']]
    ) ?: [];
    $delivery = address_input('delivery_', 'Delivery address', false);
    if (!$delivery['provided']) {
        // No structured address chosen: reuse the customer's saved address so
        // existing accounts keep their delivery location and map pin.
        if ($delivery['street'] === null && $delivery['barangay'] === null) {
            foreach (['street', 'barangay', 'city', 'province', 'region', 'postal_code', 'latitude', 'longitude'] as $k) {
                $delivery[$k] = $cust[$k] ?? null;
            }
        }
        if ($delivery['address'] === null) {
            $delivery['address'] = $cust['address'] ?? null;
        }
    }
    $isRush = input_bool('is_rush', false);
    $isOutside = input_bool('is_outside_zone', false);
    $fees = order_fee_values($branch, $isRush, $isOutside);
    $valve = input_enum('valve_type', 'Valve type', ['snap_on', 'pol', 'na'], false) ?: 'na';

    // Rush is a same-day service: the requested date is always today and the
    // branch cut-off does not apply to it.
    if ($isRush) {
        $requested = today();
    }
    if ($requested < today()) {
        fail('Requested delivery date cannot be in the past.', 422, 'validation_error', ['field' => 'requested_date']);
    }
    // Same-day cutoff (owner-configurable per branch).
    if (!$isRush && $requested === today() && !empty($branch['cutoff_time'])) {
        $cutoff = substr((string) $branch['cutoff_time'], 0, 5);
        if ((new DateTimeImmutable('now'))->format('H:i') > $cutoff) {
            fail('Same-day orders for this branch closed at ' . $cutoff . '. Choose a later date.', 409, 'cutoff_passed');
        }
    }

    $orderId = tx(function () use ($u, $branchId, $items, $payment, $notes, $requested, $timeSlot, $delivery, $valve, $fees) {
        $priced = price_order_items((int) $branchId, $items, (string) $payment, (int) $u['customer_id'], $fees);
        q(
            'INSERT INTO orders (branch_id, customer_id, payment_type, notes, status, order_date, requested_date,
                time_slot, delivery_address, delivery_street, delivery_barangay, delivery_city, delivery_province,
                delivery_region, delivery_postal_code, delivery_latitude, delivery_longitude, valve_type,
                is_rush, rush_fee, is_outside_zone, outside_zone_fee,
                subtotal, deposit_total, total_amount, created_by, size_id, qty)
             VALUES (?,?,?,?,"pending",?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                (int) $branchId, (int) $u['customer_id'], $payment, $notes === '' ? null : $notes, today(),
                $requested, $timeSlot === '' ? null : $timeSlot, $delivery['address'], $delivery['street'],
                $delivery['barangay'], $delivery['city'], $delivery['province'], $delivery['region'],
                $delivery['postal_code'], $delivery['latitude'], $delivery['longitude'], $valve,
                $priced['is_rush'], $priced['rush_fee'], $priced['is_outside_zone'], $priced['outside_zone_fee'],
                $priced['subtotal'], $priced['deposit_total'], $priced['total_amount'], (int) $u['id'],
                $priced['items'][0]['size_id'], array_sum(array_column($priced['items'], 'qty')),
            ]
        );
        $id = (int) db()->lastInsertId();
        foreach ($priced['items'] as $it) {
            q(
                'INSERT INTO order_items (order_id, size_id, qty, unit_price, deposit_amount, line_total) VALUES (?,?,?,?,?,?)',
                [$id, $it['size_id'], $it['qty'], $it['unit_price'], $it['deposit_amount'], $it['line_total']]
            );
        }
        q('UPDATE orders SET order_no=? WHERE id=?', [doc_no('ORD', today(), $id), $id]);
        return $id;
    });

    $o = row('SELECT o.*, b.name branch_name, r.name rider_name FROM orders o JOIN branches b ON b.id=o.branch_id LEFT JOIN users r ON r.id=o.rider_id WHERE o.id=?', [$orderId]);
    audit($u, 'create', 'order', $orderId, null, ['status' => 'pending', 'total_amount' => $o['total_amount'], 'branch_id' => (int) $branchId], (int) $branchId);
    json_response(order_payload($o), 201);
}

function api_my_order_update(array $params): void
{
    $u = require_customer();
    $orderId = (int) $params[0];
    $changed = tx(function () use ($orderId, $u) {
        // Lock the order row: prevents two edits or an edit racing a delivery.
        $o = row('SELECT * FROM orders WHERE id=? AND customer_id=? FOR UPDATE', [$orderId, (int) $u['customer_id']]);
        if (!$o) {
            fail('Order not found.', 404, 'not_found');
        }
        if ($o['status'] !== 'pending') {
            fail('Only pending orders can be edited.', 409, 'order_locked');
        }
        $old = ['payment_type' => $o['payment_type'], 'notes' => $o['notes'], 'total_amount' => (float) $o['total_amount']];

        $branchId = (int) (body_value('branch_id') ?? $o['branch_id']);
        $branch = require_active_branch($branchId);
        $items = parse_order_items();
        $totalQty = array_sum(array_column($items, 'qty'));
        if ($totalQty > (int) $branch['max_order_qty']) {
            fail('This branch accepts at most ' . (int) $branch['max_order_qty'] . ' cylinders per order.', 422, 'order_limit');
        }
        $payment = body_value('pay') !== null ? input_enum('pay', 'Payment type', ['cash', 'credit']) : $o['payment_type'];
        $notes = body_value('notes') !== null ? input_str('notes', 'Notes', 0, 200, false) : (string) ($o['notes'] ?? '');
        $requested = body_value('requested_date') !== null ? input_date('requested_date', 'Requested delivery date') : ($o['requested_date'] ?: today());
        $timeSlot = body_value('time_slot') !== null ? input_str('time_slot', 'Time slot', 0, 40, false) : (string) ($o['time_slot'] ?? '');
        if (address_provided('delivery_')) {
            $delivery = address_input('delivery_', 'Delivery address', false);
        } else {
            $delivery = [
                'address' => $o['delivery_address'], 'street' => $o['delivery_street'],
                'barangay' => $o['delivery_barangay'], 'city' => $o['delivery_city'],
                'province' => $o['delivery_province'], 'region' => $o['delivery_region'],
                'postal_code' => $o['delivery_postal_code'], 'latitude' => $o['delivery_latitude'],
                'longitude' => $o['delivery_longitude'],
            ];
        }
        $isRush = body_has('is_rush') ? input_bool('is_rush', false) : ((int) $o['is_rush'] === 1);
        $isOutside = body_has('is_outside_zone') ? input_bool('is_outside_zone', false) : ((int) $o['is_outside_zone'] === 1);
        $fees = order_fee_values($branch, $isRush, $isOutside);
        $valve = body_value('valve_type') !== null ? input_enum('valve_type', 'Valve type', ['snap_on', 'pol', 'na']) : $o['valve_type'];

        // A rush order always stays on today's schedule.
        if ($isRush) {
            $requested = today();
        }
        if ($requested < today()) {
            fail('Requested delivery date cannot be in the past.', 422, 'validation_error', ['field' => 'requested_date']);
        }

        $priced = price_order_items($branchId, $items, (string) $payment, (int) $u['customer_id'], $fees);
        q('DELETE FROM order_items WHERE order_id=?', [$orderId]);
        foreach ($priced['items'] as $it) {
            q(
                'INSERT INTO order_items (order_id, size_id, qty, unit_price, deposit_amount, line_total) VALUES (?,?,?,?,?,?)',
                [$orderId, $it['size_id'], $it['qty'], $it['unit_price'], $it['deposit_amount'], $it['line_total']]
            );
        }
        q(
            'UPDATE orders SET branch_id=?, payment_type=?, notes=?, requested_date=?, time_slot=?,
                delivery_address=?, delivery_street=?, delivery_barangay=?, delivery_city=?, delivery_province=?,
                delivery_region=?, delivery_postal_code=?, delivery_latitude=?, delivery_longitude=?,
                valve_type=?, is_rush=?, rush_fee=?, is_outside_zone=?, outside_zone_fee=?,
                subtotal=?, deposit_total=?, total_amount=?, size_id=?, qty=? WHERE id=?',
            [
                $branchId, $payment, $notes === '' ? null : $notes, $requested, $timeSlot === '' ? null : $timeSlot,
                $delivery['address'], $delivery['street'], $delivery['barangay'], $delivery['city'],
                $delivery['province'], $delivery['region'], $delivery['postal_code'], $delivery['latitude'],
                $delivery['longitude'], $valve, $priced['is_rush'], $priced['rush_fee'], $priced['is_outside_zone'],
                $priced['outside_zone_fee'], $priced['subtotal'], $priced['deposit_total'], $priced['total_amount'],
                $priced['items'][0]['size_id'], $totalQty, $orderId,
            ]
        );
        return ['old' => $old, 'new' => ['payment_type' => $payment, 'total_amount' => $priced['total_amount']], 'branch_id' => $branchId];
    });

    audit($u, 'update', 'order', $orderId, $changed['old'], $changed['new'], $changed['branch_id']);
    $o = row('SELECT o.*, b.name branch_name, r.name rider_name FROM orders o JOIN branches b ON b.id=o.branch_id LEFT JOIN users r ON r.id=o.rider_id WHERE o.id=?', [$orderId]);
    json_response(order_payload($o));
}

function api_my_order_cancel(array $params): void
{
    $u = require_customer();
    $orderId = (int) $params[0];
    $branchId = tx(function () use ($orderId, $u) {
        $o = row('SELECT * FROM orders WHERE id=? AND customer_id=? FOR UPDATE', [$orderId, (int) $u['customer_id']]);
        if (!$o) {
            fail('Order not found.', 404, 'not_found');
        }
        if ($o['status'] !== 'pending') {
            fail('Only pending orders can be cancelled.', 409, 'order_locked');
        }
        q("UPDATE orders SET status='cancelled' WHERE id=?", [$orderId]);
        return (int) $o['branch_id'];
    });
    audit($u, 'cancel', 'order', $orderId, ['status' => 'pending'], ['status' => 'cancelled'], $branchId);
    ok();
}

function api_my_payments(): void
{
    $u = require_customer();
    $p = pagination();
    $where = 'p.customer_id=?';
    $params = [(int) $u['customer_id']];
    $total = (int) val('SELECT COUNT(*) FROM payments p WHERE ' . $where, $params, 0);
    $st = db()->prepare(
        'SELECT p.id, p.payment_no, p.payment_date, p.amount, p.method, p.reference_no, p.is_void, b.name branch_name
         FROM payments p JOIN branches b ON b.id=p.branch_id
         WHERE ' . $where . ' ORDER BY p.id DESC LIMIT ? OFFSET ?'
    );
    bind_page($st, $params, $p['per'], $p['offset']);
    $st->execute();
    paginate_headers($total, $p['page'], $p['per']);
    json_response($st->fetchAll());
}
