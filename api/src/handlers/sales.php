<?php
declare(strict_types=1);

function sale_payload(array $s, array $items): array
{
    foreach ($items as &$it) {
        $it['size_id'] = (int) $it['size_id'];
        $it['qty'] = (int) $it['qty'];
        $it['unit_price'] = (float) $it['unit_price'];
        $it['empties_received'] = (int) $it['empties_received'];
        $it['deposit_amount'] = (float) $it['deposit_amount'];
        $it['line_total'] = (float) $it['line_total'];
    }
    unset($it);
    $first = $items[0] ?? null;
    return [
        'id' => (int) $s['id'],
        'sale_no' => $s['sale_no'] ?? null,
        'branch_id' => (int) $s['branch_id'],
        'branch_name' => $s['branch_name'] ?? null,
        'sale_date' => $s['sale_date'],
        'customer_id' => $s['customer_id'] === null ? null : (int) $s['customer_id'],
        'customer' => $s['customer'] ?? 'Walk-in',
        'payment_type' => $s['payment_type'],
        'subtotal' => (float) $s['subtotal'],
        'deposit_total' => (float) $s['deposit_total'],
        'total_amount' => (float) $s['total_amount'],
        'is_void' => (int) $s['is_void'],
        'void_reason' => $s['void_reason'],
        'created_at' => $s['created_at'],
        'items' => $items,
        // Legacy fields (single-line clients).
        'size' => $first ? $first['size'] : null,
        'qty' => array_sum(array_column($items, 'qty')),
        'unit_price' => $first ? $first['unit_price'] : 0,
        'empties_received' => array_sum(array_column($items, 'empties_received')),
    ];
}

function sales_items_batch(array $saleIds): array
{
    if (!$saleIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($saleIds), '?'));
    $rows = rows(
        "SELECT si.*, z.label AS size FROM sale_items si JOIN cylinder_sizes z ON z.id=si.size_id
         WHERE si.sale_id IN ($in) ORDER BY si.id",
        array_values($saleIds)
    );
    $out = [];
    foreach ($rows as $r) {
        $out[(int) $r['sale_id']][] = $r;
    }
    return $out;
}

function load_sale_for_user(array $u, int $id): array
{
    $s = row(
        'SELECT s.*, b.name branch_name, c.name customer
         FROM sales s JOIN branches b ON b.id=s.branch_id LEFT JOIN customers c ON c.id=s.customer_id
         WHERE s.id=?',
        [$id]
    );
    if (!$s) {
        fail('Sale not found.', 404, 'not_found');
    }
    if (!can_access_branch($u, (int) $s['branch_id'])) {
        fail('You do not have access to that sale.', 403, 'branch_forbidden');
    }
    return $s;
}

function api_sales_list(): void
{
    $u = require_staff_or_owner();
    $scope = branch_scope($u);
    [$sc, $sp] = scope_sql($scope, 's.branch_id');
    $p = pagination();
    $where = '1=1' . $sc;
    $params = $sp;

    if (($v = query_str('payment_type', 10)) !== '') {
        if (!in_array($v, ['cash', 'credit'], true)) {
            fail('Invalid payment filter.', 422, 'validation_error');
        }
        $where .= ' AND s.payment_type=?';
        $params[] = $v;
    }
    if (($v = query_str('from', 10)) !== '') {
        $where .= ' AND s.sale_date>=?';
        $params[] = $v;
    }
    if (($v = query_str('to', 10)) !== '') {
        $where .= ' AND s.sale_date<=?';
        $params[] = $v;
    }
    if ($p['q'] !== '') {
        $where .= ' AND (s.sale_no LIKE ? OR c.name LIKE ?)';
        $like = '%' . $p['q'] . '%';
        $params[] = $like;
        $params[] = $like;
    }

    $total = (int) val(
        'SELECT COUNT(*) FROM sales s LEFT JOIN customers c ON c.id=s.customer_id WHERE ' . $where,
        $params,
        0
    );
    $st = db()->prepare(
        'SELECT s.*, b.name branch_name, c.name customer
         FROM sales s JOIN branches b ON b.id=s.branch_id LEFT JOIN customers c ON c.id=s.customer_id
         WHERE ' . $where . ' ORDER BY s.sale_date DESC, s.id DESC LIMIT ? OFFSET ?'
    );
    bind_page($st, $params, $p['per'], $p['offset']);
    $st->execute();
    $sales = $st->fetchAll();
    $items = sales_items_batch(array_map(static fn($s) => (int) $s['id'], $sales));
    paginate_headers($total, $p['page'], $p['per']);
    json_response(array_map(static fn($s) => sale_payload($s, $items[(int) $s['id']] ?? []), $sales));
}

/** Validate items for a direct sale. Accepts the legacy single item too. */
function parse_sale_items(): array
{
    $items = body_value('items');
    if (($items === null || $items === []) && body_value('size_id') !== null) {
        $items = [[
            'size_id' => body_value('size_id'),
            'qty' => body_value('qty'),
            'price' => body_value('price'),
            'back' => body_value('back', 0),
        ]];
    }
    if (!is_array($items) || count($items) === 0) {
        fail('Add at least one cylinder size.', 422, 'validation_error', ['field' => 'items']);
    }
    if (count($items) > 10) {
        fail('A sale can have at most 10 line items.', 422, 'validation_error', ['field' => 'items']);
    }
    $clean = [];
    $seen = [];
    foreach ($items as $it) {
        if (!is_array($it)) {
            fail('Invalid sale item.', 422, 'validation_error', ['field' => 'items']);
        }
        $sid = isset($it['size_id']) && is_numeric($it['size_id']) ? (int) $it['size_id'] : 0;
        $qty = isset($it['qty']) && is_numeric($it['qty']) ? (float) $it['qty'] : 0;
        $back = isset($it['back']) && $it['back'] !== '' && is_numeric($it['back']) ? (float) $it['back'] : 0.0;
        if ($sid < 1 || (int) val('SELECT id FROM cylinder_sizes WHERE id=? AND is_active=1', [$sid], 0) !== $sid) {
            fail('One of the selected cylinder sizes is not available.', 422, 'validation_error', ['field' => 'items']);
        }
        if (floor($qty) !== $qty || $qty < 1 || $qty > 200) {
            fail('Quantity must be a whole number between 1 and 200.', 422, 'validation_error', ['field' => 'items']);
        }
        if (floor($back) !== $back || $back < 0 || $back > 1000) {
            fail('Empties received must be a whole number between 0 and 1000.', 422, 'validation_error', ['field' => 'items']);
        }
        if (isset($seen[$sid])) {
            fail('Each cylinder size may only appear once per sale.', 422, 'validation_error', ['field' => 'items']);
        }
        $seen[$sid] = true;
        $price = isset($it['price']) && $it['price'] !== '' && is_numeric($it['price']) ? (float) $it['price'] : null;
        if ($price !== null && ($price < 0 || $price > 1000000)) {
            fail('Invalid unit price.', 422, 'validation_error', ['field' => 'items']);
        }
        $clean[] = ['size_id' => $sid, 'qty' => (int) $qty, 'back' => (int) $back, 'price' => $price];
    }
    return $clean;
}

function api_sales_create(): void
{
    $u = require_staff_or_owner();
    $scope = branch_scope($u, true);
    $branchId = (int) $scope['id'];
    require_active_branch($branchId);

    $date = input_date('date', 'Sale date', false) ?: today();
    $pay = input_enum('pay', 'Payment type', ['cash', 'credit']);
    $customerId = input_int('customer_id', 'Customer', 1, null, false);
    if ($pay === 'credit' && !$customerId) {
        fail('Choose a customer for a credit sale.', 422, 'validation_error', ['field' => 'customer_id']);
    }
    if ($customerId !== null) {
        $c = row('SELECT id, is_active FROM customers WHERE id=?', [$customerId]);
        if (!$c || (int) $c['is_active'] !== 1) {
            fail('Customer not found or inactive.', 422, 'validation_error', ['field' => 'customer_id']);
        }
    }

    $items = parse_sale_items();
    $result = tx(function () use ($u, $branchId, $date, $pay, $customerId, $items) {
        $sizeIds = array_map(static fn($x) => (int) $x['size_id'], $items);
        lock_stock($branchId, $sizeIds);
        $stock = stock_balances($branchId, $sizeIds);
        foreach ($items as &$it) {
            $sid = (int) $it['size_id'];
            if ($stock[$sid]['full_qty'] < $it['qty']) {
                fail('Only ' . $stock[$sid]['full_qty'] . ' full ' . size_label($sid) . ' cylinder(s) in stock at this branch.', 409, 'insufficient_stock');
            }
            // Guard every bucket, not just full cylinders: returning more
            // empties than were sold must not drive "with customers" negative.
            assert_stock_after($branchId, $sid, -$it['qty'], $it['back'], $it['qty'] - $it['back']);
            $it['unit_price'] = $it['price'] !== null ? round((float) $it['price'], 2) : resolve_price($branchId, $sid);
            $it['line_total'] = round($it['qty'] * $it['unit_price'], 2);
        }
        unset($it);

        $subtotal = array_sum(array_column($items, 'line_total'));
        $total = round($subtotal, 2);

        if ($pay === 'credit' && $customerId !== null) {
            $cust = row('SELECT credit_limit FROM customers WHERE id=?', [$customerId]);
            if ($cust && $cust['credit_limit'] !== null) {
                $bal = customer_balance((int) $customerId, $branchId);
                if ($bal['balance'] + $total > (float) $cust['credit_limit']) {
                    fail('This sale would exceed the customer credit limit.', 409, 'credit_limit');
                }
            }
        }

        q(
            'INSERT INTO sales (branch_id, sale_date, customer_id, payment_type, subtotal, deposit_total, total_amount, created_by)
             VALUES (?,?,?,?,?,0,?,?)',
            [$branchId, $date, $customerId, $pay, $subtotal, $total, (int) $u['id']]
        );
        $saleId = (int) db()->lastInsertId();
        q('UPDATE sales SET sale_no=? WHERE id=?', [doc_no('SALE', $date, $saleId), $saleId]);

        foreach ($items as $it) {
            $sid = (int) $it['size_id'];
            q(
                'INSERT INTO sale_items (sale_id, size_id, qty, unit_price, empties_received, deposit_amount, line_total)
                 VALUES (?,?,?,?,?,0,?)',
                [$saleId, $sid, $it['qty'], $it['unit_price'], $it['back'], $it['line_total']]
            );
            movement($branchId, $date, $sid, 'sale', $saleId, -$it['qty'], $it['back'], $it['qty'] - $it['back'], (int) $u['id'], 'Direct sale');
        }
        return ['id' => $saleId, 'subtotal' => $subtotal, 'total' => $total];
    });

    audit($u, 'create', 'sale', $result['id'], null, ['total_amount' => $result['total'], 'payment_type' => $pay, 'branch_id' => $branchId], $branchId);
    $s = load_sale_for_user($u, $result['id']);
    $items = sales_items_batch([$result['id']]);
    json_response(sale_payload($s, $items[$result['id']] ?? []), 201);
}

function api_sales_show(array $params): void
{
    $u = require_staff_or_owner();
    $s = load_sale_for_user($u, (int) $params[0]);
    $items = sales_items_batch([(int) $s['id']]);
    json_response(sale_payload($s, $items[(int) $s['id']] ?? []));
}

function api_sales_receipt(array $params): void
{
    $u = require_staff_or_owner();
    $s = load_sale_for_user($u, (int) $params[0]);
    $items = sales_items_batch([(int) $s['id']]);
    $payload = sale_payload($s, $items[(int) $s['id']] ?? []);
    $branch = branch_row((int) $s['branch_id']);
    $payload['branch'] = [
        'name' => $branch['name'],
        'address' => $branch['address'],
        'contact_phone' => $branch['contact_phone'],
    ];
    $payload['customer_address'] = $s['customer_id'] ? val('SELECT address FROM customers WHERE id=?', [(int) $s['customer_id']], null) : null;
    json_response($payload);
}

function api_sales_void(array $params): void
{
    $u = require_owner();
    $saleId = (int) $params[0];
    $reason = input_str('reason', 'Void reason', 3, 200, true);
    $branchId = tx(function () use ($u, $saleId, $reason) {
        $s = row('SELECT * FROM sales WHERE id=? FOR UPDATE', [$saleId]);
        if (!$s) {
            fail('Sale not found.', 404, 'not_found');
        }
        if ((int) $s['is_void'] === 1) {
            fail('This sale is already void.', 409, 'already_void');
        }
        $items = rows('SELECT * FROM sale_items WHERE sale_id=?', [$saleId]);
        $sizeIds = array_map(static fn($x) => (int) $x['size_id'], $items);
        lock_stock((int) $s['branch_id'], $sizeIds);
        foreach ($items as $it) {
            $qty = (int) $it['qty'];
            $back = (int) $it['empties_received'];
            // Reverse the original ledger exactly.
            assert_stock_after((int) $s['branch_id'], (int) $it['size_id'], $qty, -$back, -($qty - $back));
        }
        foreach ($items as $it) {
            $qty = (int) $it['qty'];
            $back = (int) $it['empties_received'];
            movement((int) $s['branch_id'], today(), (int) $it['size_id'], 'void', $saleId, $qty, -$back, -($qty - $back), (int) $u['id'], 'Void: ' . $reason);
        }
        q('UPDATE sales SET is_void=1, voided_at=?, voided_by=?, void_reason=? WHERE id=?', [now_iso(), (int) $u['id'], $reason, $saleId]);
        return (int) $s['branch_id'];
    });
    audit($u, 'void', 'sale', $saleId, ['is_void' => 0], ['is_void' => 1, 'reason' => $reason], $branchId);
    ok(['ok' => 1, 'voided' => true]);
}
