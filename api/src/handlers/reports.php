<?php
declare(strict_types=1);

/**
 * Reports. Every report respects the caller's branch scope:
 *   - staff: always their own branch
 *   - owner: one branch (X-Branch-Id / ?branch_id=) or all branches
 * Add ?format=csv to any report for a spreadsheet export.
 */

function report_date(string $key, string $fallback): string
{
    $v = query_str($key, 10);
    if ($v === '') {
        return $fallback;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        fail('Date filters must use YYYY-MM-DD.', 422, 'validation_error', ['field' => $key]);
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    if (!$d || $d->format('Y-m-d') !== $v) {
        fail('Invalid date filter.', 422, 'validation_error', ['field' => $key]);
    }
    return $v;
}

function report_range(string $defaultFrom = ''): array
{
    $from = report_date('from', $defaultFrom === '' ? today() : $defaultFrom);
    $to = report_date('to', $from);
    if ($to < $from) {
        fail('The end date cannot be before the start date.', 422, 'validation_error', ['field' => 'to']);
    }
    $start = new DateTimeImmutable($from);
    $end = new DateTimeImmutable($to);
    if ($start->diff($end)->days > 366) {
        fail('Choose a date range of one year or less.', 422, 'validation_error', ['field' => 'from']);
    }
    return [$from, $to];
}

function report_wants_csv(): bool
{
    return query_str('format', 10) === 'csv';
}

function report_scope_params(array $scope): array
{
    return $scope['mode'] === 'branch' ? [(int) $scope['id']] : [];
}

function report_branch_condition(array $scope, string $column): array
{
    if ($scope['mode'] === 'branch') {
        return [' AND ' . $column . ' = ?', [(int) $scope['id']]];
    }
    return ['', []];
}

/* ------------------------------------------------------------------------- */
/* Daily takings                                                             */
/* ------------------------------------------------------------------------- */

function api_report_daily(): void
{
    $u = require_staff_or_owner();
    $scope = branch_scope($u);
    [$from, $to] = report_range();
    [$sc, $sp] = report_branch_condition($scope, 'branch_id');

    $rows = rows(
        "SELECT d.d AS report_date,
            COALESCE((SELECT SUM(total_amount) FROM sales s WHERE s.sale_date=d.d AND s.is_void=0 AND s.payment_type='cash'{$sc}),0) cash_sales,
            COALESCE((SELECT SUM(total_amount) FROM sales s WHERE s.sale_date=d.d AND s.is_void=0 AND s.payment_type='credit'{$sc}),0) credit_sales,
            COALESCE((SELECT SUM(total_amount) FROM sales s WHERE s.sale_date=d.d AND s.is_void=0{$sc}),0) total_sales,
            COALESCE((SELECT SUM(amount) FROM payments p WHERE p.payment_date=d.d AND p.is_void=0{$sc}),0) payments,
            COALESCE((SELECT SUM(deposit_total) FROM sales s WHERE s.sale_date=d.d AND s.is_void=0{$sc}),0) deposits,
            COALESCE((SELECT COUNT(*) FROM sales s WHERE s.sale_date=d.d AND s.is_void=1{$sc}),0) voided_sales,
            COALESCE((SELECT SUM(i.full_received) FROM plant_deliveries pd JOIN plant_delivery_items i ON i.delivery_id=pd.id
                      WHERE pd.delivery_date=d.d AND pd.is_void=0{$sc}),0) full_received,
            COALESCE((SELECT SUM(i.empties_returned) FROM plant_deliveries pd JOIN plant_delivery_items i ON i.delivery_id=pd.id
                      WHERE pd.delivery_date=d.d AND pd.is_void=0{$sc}),0) empties_returned
         FROM (
             SELECT sale_date d FROM sales WHERE sale_date BETWEEN ? AND ?" . ($scope['mode'] === 'branch' ? ' AND branch_id=?' : '') . "
             UNION
             SELECT payment_date FROM payments WHERE payment_date BETWEEN ? AND ?" . ($scope['mode'] === 'branch' ? ' AND branch_id=?' : '') . "
             UNION
             SELECT delivery_date FROM plant_deliveries WHERE delivery_date BETWEEN ? AND ?" . ($scope['mode'] === 'branch' ? ' AND branch_id=?' : '') . "
         ) d
         ORDER BY d.d DESC",
        array_merge(
            // Outer SELECT subqueries: cash, credit, total, payments,
            // deposits, voided sales, full received, empties returned.
            $sp, $sp, $sp, $sp, $sp, $sp, $sp, $sp,
            // Date union blocks (each is BETWEEN ? AND ? plus optional branch).
            [$from, $to], $sp,
            [$from, $to], $sp,
            [$from, $to], $sp
        )
    );
    foreach ($rows as &$r) {
        foreach (['cash_sales', 'credit_sales', 'total_sales', 'payments', 'deposits'] as $k) {
            $r[$k] = money($r[$k]);
        }
        $r['cash_collected'] = money($r['cash_sales'] + $r['payments']);
        $r['voided_sales'] = (int) $r['voided_sales'];
        $r['full_received'] = (int) $r['full_received'];
        $r['empties_returned'] = (int) $r['empties_returned'];
    }
    unset($r);

    $totals = [
        'cash_sales' => money(array_sum(array_column($rows, 'cash_sales'))),
        'credit_sales' => money(array_sum(array_column($rows, 'credit_sales'))),
        'total_sales' => money(array_sum(array_column($rows, 'total_sales'))),
        'payments' => money(array_sum(array_column($rows, 'payments'))),
        'cash_collected' => money(array_sum(array_column($rows, 'cash_collected'))),
        'deposits' => money(array_sum(array_column($rows, 'deposits'))),
        'voided_sales' => array_sum(array_column($rows, 'voided_sales')),
        'full_received' => array_sum(array_column($rows, 'full_received')),
        'empties_returned' => array_sum(array_column($rows, 'empties_returned')),
    ];

    if (report_wants_csv()) {
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                $r['report_date'], $r['cash_sales'], $r['credit_sales'], $r['total_sales'],
                $r['payments'], $r['cash_collected'], $r['deposits'], $r['voided_sales'],
                $r['full_received'], $r['empties_returned'],
            ];
        }
        csv_download(
            'daily-report-' . $from . '-to-' . $to . '.csv',
            ['Date', 'Cash sales', 'Credit sales', 'Total sales', 'Credit payments', 'Cash collected',
                'Deposits', 'Voided sales', 'Full received', 'Empties returned'],
            $out
        );
    }

    json_response([
        'branch' => ['mode' => $scope['mode'], 'id' => $scope['id'], 'name' => $scope['name']],
        'from' => $from,
        'to' => $to,
        'rows' => $rows,
        'totals' => $totals,
    ]);
}

/* ------------------------------------------------------------------------- */
/* Sales register                                                            */
/* ------------------------------------------------------------------------- */

function api_report_sales(): void
{
    $u = require_staff_or_owner();
    $scope = branch_scope($u);
    [$from, $to] = report_range((new DateTimeImmutable('first day of this month'))->format('Y-m-d'));
    [$sc, $sp] = report_branch_condition($scope, 's.branch_id');

    $where = 's.sale_date BETWEEN ? AND ?' . $sc;
    $params = array_merge([$from, $to], $sp);
    if (($pt = query_str('payment_type', 10)) !== '') {
        if (!in_array($pt, ['cash', 'credit'], true)) {
            fail('Invalid payment filter.', 422, 'validation_error');
        }
        $where .= ' AND s.payment_type=?';
        $params[] = $pt;
    }
    if (($cid = query_int('customer_id', null, 1)) !== null) {
        $where .= ' AND s.customer_id=?';
        $params[] = $cid;
    }
    if (($q = query_str('q', 100)) !== '') {
        $where .= ' AND (s.sale_no LIKE ? OR c.name LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
    }

    $rows = rows(
        'SELECT s.id, s.sale_no, s.sale_date, s.payment_type, s.subtotal, s.deposit_total, s.total_amount,
                s.is_void, s.void_reason, b.name branch_name, c.name customer,
                (SELECT COALESCE(SUM(qty),0) FROM sale_items i WHERE i.sale_id=s.id) qty,
                (SELECT COUNT(*) FROM sale_items i WHERE i.sale_id=s.id) line_count
         FROM sales s
         JOIN branches b ON b.id=s.branch_id
         LEFT JOIN customers c ON c.id=s.customer_id
         WHERE ' . $where . '
         ORDER BY s.sale_date DESC, s.id DESC
         LIMIT 5000',
        $params
    );
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['qty'] = (int) $r['qty'];
        $r['line_count'] = (int) $r['line_count'];
        $r['subtotal'] = money($r['subtotal']);
        $r['deposit_total'] = money($r['deposit_total']);
        $r['total_amount'] = money($r['total_amount']);
        $r['is_void'] = (int) $r['is_void'];
    }
    unset($r);

    if (report_wants_csv()) {
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                $r['sale_no'], $r['sale_date'], $r['branch_name'], $r['customer'] ?? 'Walk-in',
                $r['payment_type'], $r['qty'], $r['subtotal'], $r['deposit_total'],
                $r['total_amount'], $r['is_void'] ? 'void' : 'posted', $r['void_reason'] ?? '',
            ];
        }
        csv_download(
            'sales-register-' . $from . '-to-' . $to . '.csv',
            ['Sale no.', 'Date', 'Branch', 'Customer', 'Payment', 'Qty', 'Subtotal', 'Deposit',
                'Total', 'Status', 'Void reason'],
            $out
        );
    }

    json_response([
        'branch' => ['mode' => $scope['mode'], 'id' => $scope['id'], 'name' => $scope['name']],
        'from' => $from,
        'to' => $to,
        'rows' => $rows,
        'totals' => [
            'sales' => money(array_sum(array_column($rows, 'total_amount'))),
            'voided' => money(array_sum(array_map(static fn($r) => $r['is_void'] ? $r['total_amount'] : 0, $rows))),
            'transactions' => count($rows),
        ],
    ]);
}

/* ------------------------------------------------------------------------- */
/* Outstanding balances and empties                                          */
/* ------------------------------------------------------------------------- */

function api_report_unpaid(): void
{
    $u = require_staff_or_owner();
    $scope = branch_scope($u);
    [$scCharged, $pCharged] = report_branch_condition($scope, 's.branch_id');
    [$scPaid, $pPaid] = report_branch_condition($scope, 'p.branch_id');

    $rows = rows(
        "SELECT c.id, c.name, c.contact, c.address, c.credit_limit,
            COALESCE((SELECT SUM(i.line_total) FROM sales s JOIN sale_items i ON i.sale_id=s.id
                      WHERE s.customer_id=c.id AND s.is_void=0 AND s.payment_type='credit'{$scCharged}),0) charged,
            COALESCE((SELECT SUM(p.amount) FROM payments p
                      WHERE p.customer_id=c.id AND p.is_void=0{$scPaid}),0) paid
         FROM customers c
         WHERE c.is_active=1
         HAVING charged - paid > 0.005
         ORDER BY (charged - paid) DESC, c.name
         LIMIT 5000",
        array_merge($pCharged, $pPaid)
    );
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['charged'] = money($r['charged']);
        $r['paid'] = money($r['paid']);
        $r['balance'] = money($r['charged'] - $r['paid']);
        $r['credit_limit'] = $r['credit_limit'] === null ? null : money($r['credit_limit']);
        $r['over_limit'] = $r['credit_limit'] !== null && $r['balance'] > $r['credit_limit'];
    }
    unset($r);

    if (report_wants_csv()) {
        $out = [];
        foreach ($rows as $r) {
            $out[] = [$r['name'], $r['contact'], $r['address'], $r['charged'], $r['paid'], $r['balance'],
                $r['credit_limit'] ?? '', $r['over_limit'] ? 'yes' : 'no'];
        }
        csv_download('unpaid-balances-' . today() . '.csv',
            ['Customer', 'Contact', 'Address', 'Charged', 'Paid', 'Balance', 'Credit limit', 'Over limit'],
            $out);
    }

    json_response([
        'branch' => ['mode' => $scope['mode'], 'id' => $scope['id'], 'name' => $scope['name']],
        'as_of' => now_iso(),
        'rows' => $rows,
        'total_balance' => money(array_sum(array_column($rows, 'balance'))),
    ]);
}

function api_report_empties(): void
{
    $u = require_staff_or_owner();
    $scope = branch_scope($u);
    [$sc, $sp] = report_branch_condition($scope, 's.branch_id');

    $rows = rows(
        "SELECT c.id, c.name, c.contact, c.address,
            COALESCE((SELECT SUM(i.qty-i.empties_received)
                      FROM sales s JOIN sale_items i ON i.sale_id=s.id
                      WHERE s.customer_id=c.id AND s.is_void=0{$sc}),0) empties_owed
         FROM customers c
         WHERE c.is_active=1
         HAVING empties_owed > 0
         ORDER BY empties_owed DESC, c.name
         LIMIT 5000",
        $sp
    );
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['empties_owed'] = (int) $r['empties_owed'];
    }
    unset($r);

    if (report_wants_csv()) {
        $out = [];
        foreach ($rows as $r) {
            $out[] = [$r['name'], $r['contact'], $r['address'], $r['empties_owed']];
        }
        csv_download('empties-owed-' . today() . '.csv', ['Customer', 'Contact', 'Address', 'Empties owed'], $out);
    }

    json_response([
        'branch' => ['mode' => $scope['mode'], 'id' => $scope['id'], 'name' => $scope['name']],
        'as_of' => now_iso(),
        'rows' => $rows,
        'total_empties' => array_sum(array_column($rows, 'empties_owed')),
    ]);
}

/* ------------------------------------------------------------------------- */
/* Stock on hand                                                             */
/* ------------------------------------------------------------------------- */

function api_report_stock(): void
{
    $u = require_staff_or_owner();
    $scope = branch_scope($u);
    [$sc, $sp] = report_branch_condition($scope, 'm.branch_id');

    $rows = rows(
        'SELECT b.id branch_id, b.name branch_name, z.id size_id, z.label size,
                COALESCE(SUM(m.full_change),0) full_qty,
                COALESCE(SUM(m.empty_change),0) empty_qty,
                COALESCE(SUM(m.out_change),0) out_qty
         FROM branches b
         CROSS JOIN cylinder_sizes z
         LEFT JOIN stock_movements m ON m.branch_id=b.id AND m.size_id=z.id' . $sc . '
         WHERE z.is_active=1' . ($scope['mode'] === 'branch' ? ' AND b.id=? AND b.is_active=1' : ' AND b.is_active=1') . '
         GROUP BY b.id, b.name, z.id, z.label
         ORDER BY b.name, z.id',
        $scope['mode'] === 'branch' ? [$scope['id'], $scope['id']] : []
    );
    foreach ($rows as &$r) {
        $r['branch_id'] = (int) $r['branch_id'];
        $r['size_id'] = (int) $r['size_id'];
        $r['full_qty'] = (int) $r['full_qty'];
        $r['empty_qty'] = (int) $r['empty_qty'];
        $r['out_qty'] = (int) $r['out_qty'];
        $r['total_qty'] = $r['full_qty'] + $r['empty_qty'] + $r['out_qty'];
    }
    unset($r);

    if (report_wants_csv()) {
        $out = [];
        foreach ($rows as $r) {
            $out[] = [$r['branch_name'], $r['size'], $r['full_qty'], $r['empty_qty'], $r['out_qty'], $r['total_qty']];
        }
        csv_download('stock-on-hand-' . today() . '.csv',
            ['Branch', 'Size', 'Full', 'Empty', 'With customers', 'Total'], $out);
    }

    json_response([
        'branch' => ['mode' => $scope['mode'], 'id' => $scope['id'], 'name' => $scope['name']],
        'as_of' => now_iso(),
        'rows' => $rows,
    ]);
}

/* ------------------------------------------------------------------------- */
/* Branch comparison (owner)                                                 */
/* ------------------------------------------------------------------------- */

function api_report_branch_comparison(): void
{
    $u = require_owner();
    [$from, $to] = report_range();

    $rows = rows(
        "SELECT b.id, b.name, b.code, b.is_active,
            COALESCE((SELECT SUM(s.total_amount) FROM sales s
                      WHERE s.branch_id=b.id AND s.sale_date BETWEEN ? AND ? AND s.is_void=0),0) sales_total,
            COALESCE((SELECT SUM(s.total_amount) FROM sales s
                      WHERE s.branch_id=b.id AND s.sale_date BETWEEN ? AND ? AND s.is_void=0 AND s.payment_type='cash'),0) cash_sales,
            COALESCE((SELECT SUM(s.total_amount) FROM sales s
                      WHERE s.branch_id=b.id AND s.sale_date BETWEEN ? AND ? AND s.is_void=0 AND s.payment_type='credit'),0) credit_sales,
            COALESCE((SELECT SUM(p.amount) FROM payments p
                      WHERE p.branch_id=b.id AND p.payment_date BETWEEN ? AND ? AND p.is_void=0),0) payments,
            COALESCE((SELECT SUM(i.line_total) FROM sales s JOIN sale_items i ON i.sale_id=s.id
                      WHERE s.branch_id=b.id AND s.is_void=0 AND s.payment_type='credit'),0)
            - COALESCE((SELECT SUM(p.amount) FROM payments p
                      WHERE p.branch_id=b.id AND p.is_void=0),0) unpaid,
            COALESCE((SELECT SUM(m.full_change) FROM stock_movements m WHERE m.branch_id=b.id),0) full_qty,
            COALESCE((SELECT SUM(m.empty_change) FROM stock_movements m WHERE m.branch_id=b.id),0) empty_qty,
            COALESCE((SELECT SUM(m.out_change) FROM stock_movements m WHERE m.branch_id=b.id),0) out_qty,
            (SELECT COUNT(*) FROM orders o WHERE o.branch_id=b.id AND o.status IN ('pending','approved')) open_orders
         FROM branches b
         WHERE b.is_active=1
         ORDER BY b.name",
        [$from, $to, $from, $to, $from, $to, $from, $to]
    );
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['is_active'] = (int) $r['is_active'];
        foreach (['sales_total', 'cash_sales', 'credit_sales', 'payments'] as $k) {
            $r[$k] = money($r[$k]);
        }
        $r['unpaid'] = money(max(0, $r['unpaid']));
        $r['cash_collected'] = money($r['cash_sales'] + $r['payments']);
        $r['full_qty'] = (int) $r['full_qty'];
        $r['empty_qty'] = (int) $r['empty_qty'];
        $r['out_qty'] = (int) $r['out_qty'];
        $r['open_orders'] = (int) $r['open_orders'];
    }
    unset($r);

    if (report_wants_csv()) {
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                $r['name'], $r['code'], $r['sales_total'], $r['cash_sales'], $r['credit_sales'],
                $r['payments'], $r['cash_collected'], $r['unpaid'], $r['full_qty'], $r['empty_qty'],
                $r['out_qty'], $r['open_orders'],
            ];
        }
        csv_download('branch-comparison-' . $from . '-to-' . $to . '.csv',
            ['Branch', 'Code', 'Sales', 'Cash sales', 'Credit sales', 'Credit payments', 'Cash collected',
                'Unpaid', 'Full', 'Empty', 'With customers', 'Open orders'],
            $out);
    }

    json_response([
        'from' => $from,
        'to' => $to,
        'rows' => $rows,
        'totals' => [
            'sales_total' => money(array_sum(array_column($rows, 'sales_total'))),
            'payments' => money(array_sum(array_column($rows, 'payments'))),
            'unpaid' => money(array_sum(array_column($rows, 'unpaid'))),
            'full_qty' => array_sum(array_column($rows, 'full_qty')),
            'empty_qty' => array_sum(array_column($rows, 'empty_qty')),
            'out_qty' => array_sum(array_column($rows, 'out_qty')),
        ],
    ]);
}
