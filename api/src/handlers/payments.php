<?php
declare(strict_types=1);

function api_payments_list(): void
{
    $u = require_staff_or_owner();
    $scope = branch_scope($u);
    [$sc, $sp] = scope_sql($scope, 'p.branch_id');
    $p = pagination();
    $where = '1=1' . $sc;
    $params = $sp;
    if (($cid = query_int('customer_id', null, 1)) !== null) {
        $where .= ' AND p.customer_id=?';
        $params[] = $cid;
    }
    if (($from = query_str('from', 10)) !== '') {
        $where .= ' AND p.payment_date>=?';
        $params[] = $from;
    }
    if (($to = query_str('to', 10)) !== '') {
        $where .= ' AND p.payment_date<=?';
        $params[] = $to;
    }
    if ($p['q'] !== '') {
        $where .= ' AND (p.payment_no LIKE ? OR p.reference_no LIKE ? OR c.name LIKE ?)';
        $like = '%' . $p['q'] . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    $total = (int) val('SELECT COUNT(*) FROM payments p JOIN customers c ON c.id=p.customer_id WHERE ' . $where, $params, 0);
    $st = db()->prepare(
        'SELECT p.*, c.name customer, b.name branch_name
         FROM payments p JOIN customers c ON c.id=p.customer_id JOIN branches b ON b.id=p.branch_id
         WHERE ' . $where . ' ORDER BY p.payment_date DESC, p.id DESC LIMIT ? OFFSET ?'
    );
    bind_page($st, $params, $p['per'], $p['offset']);
    $st->execute();
    paginate_headers($total, $p['page'], $p['per']);
    json_response($st->fetchAll());
}

function api_payments_create(): void
{
    $u = require_staff_or_owner();
    $scope = branch_scope($u, true);
    $branchId = (int) $scope['id'];
    require_active_branch($branchId);

    $customerId = input_int('customer_id', 'Customer', 1);
    $date = input_date('date', 'Payment date', false) ?: today();
    $amount = input_decimal('amount', 'Amount', 0.01, 100000000);
    $method = input_enum('method', 'Payment method', ['cash', 'gcash', 'maya', 'bank', 'other'], false) ?: 'cash';
    $reference = input_str('reference_no', 'Reference number', 0, 80, false);
    $notes = input_str('notes', 'Notes', 0, 200, false);

    $result = tx(function () use ($u, $branchId, $customerId, $date, $amount, $method, $reference, $notes) {
        // Lock the customer row so two cashiers cannot both collect the whole balance.
        $c = row('SELECT * FROM customers WHERE id=? AND is_active=1 FOR UPDATE', [(int) $customerId]);
        if (!$c) {
            fail('Customer not found or inactive.', 422, 'validation_error', ['field' => 'customer_id']);
        }
        $bal = customer_balance((int) $customerId, $branchId);
        if ($bal['balance'] <= 0) {
            fail('This customer has no outstanding balance in this branch.', 409, 'no_balance');
        }
        if ($amount > $bal['balance'] + 0.001) {
            fail('Payment cannot exceed the outstanding balance of ' . number_format($bal['balance'], 2) . '.', 422, 'payment_too_large');
        }
        q(
            'INSERT INTO payments (branch_id, customer_id, payment_date, amount, method, reference_no, notes, created_by)
             VALUES (?,?,?,?,?,?,?,?)',
            [$branchId, (int) $customerId, $date, $amount, $method, $reference === '' ? null : $reference, $notes === '' ? null : $notes, (int) $u['id']]
        );
        $id = (int) db()->lastInsertId();
        $no = doc_no('PAY', $date, $id);
        q('UPDATE payments SET payment_no=? WHERE id=?', [$no, $id]);
        return ['id' => $id, 'payment_no' => $no, 'balance_before' => $bal['balance'], 'balance_after' => round($bal['balance'] - $amount, 2)];
    });

    audit($u, 'create', 'payment', $result['id'], null, [
        'customer_id' => (int) $customerId,
        'amount' => $amount,
        'branch_id' => $branchId,
        'payment_no' => $result['payment_no'],
    ], $branchId);
    json_response(array_merge(['ok' => 1], $result), 201);
}

function api_payments_void(array $params): void
{
    $u = require_owner();
    $id = (int) $params[0];
    $reason = input_str('reason', 'Void reason', 3, 200, true);
    $p = row('SELECT * FROM payments WHERE id=?', [$id]);
    if (!$p) {
        fail('Payment not found.', 404, 'not_found');
    }
    if ((int) $p['is_void'] === 1) {
        fail('This payment is already void.', 409, 'already_void');
    }
    q('UPDATE payments SET is_void=1, voided_at=?, voided_by=?, void_reason=? WHERE id=?', [now_iso(), (int) $u['id'], $reason, $id]);
    audit($u, 'void', 'payment', $id, ['is_void' => 0], ['is_void' => 1, 'reason' => $reason], (int) $p['branch_id']);
    ok(['ok' => 1, 'voided' => true]);
}
