<?php
declare(strict_types=1);

function api_dashboard(): void
{
    $u = require_staff_or_owner();
    $scope = branch_scope($u);
    [$sc, $sp] = scope_sql($scope, 'branch_id');
    $d = today();

    $stockRows = rows(
        'SELECT z.id, z.label, COALESCE(SUM(m.full_change),0) full_qty,
                COALESCE(SUM(m.empty_change),0) empty_qty, COALESCE(SUM(m.out_change),0) out_qty
         FROM cylinder_sizes z
         LEFT JOIN stock_movements m ON m.size_id=z.id' . ($scope['mode'] === 'branch' ? ' AND m.branch_id=?' : '') . '
         WHERE z.is_active=1 GROUP BY z.id, z.label ORDER BY z.id',
        $scope['mode'] === 'branch' ? [(int) $scope['id']] : []
    );
    foreach ($stockRows as &$s) {
        $s['id'] = (int) $s['id'];
        $s['full_qty'] = (int) $s['full_qty'];
        $s['empty_qty'] = (int) $s['empty_qty'];
        $s['out_qty'] = (int) $s['out_qty'];
    }
    unset($s);

    $sold = (float) val(
        "SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE is_void=0 AND sale_date=?{$sc}",
        array_merge([$d], $sp),
        0
    );
    $methodTotals = ['cash' => 0.0, 'gcash' => 0.0, 'maya' => 0.0];
    foreach (rows(
        "SELECT payment_method, COALESCE(SUM(total_amount),0) total FROM sales
         WHERE is_void=0 AND sale_date=?{$sc} GROUP BY payment_method",
        array_merge([$d], $sp)
    ) as $m) {
        $key = (string) $m['payment_method'];
        if (array_key_exists($key, $methodTotals)) {
            $methodTotals[$key] = (float) $m['total'];
        }
    }
    $empties = (int) val(
        "SELECT COALESCE(SUM(i.qty-i.empties_received),0) FROM sales s JOIN sale_items i ON i.sale_id=s.id
         WHERE s.is_void=0{$sc}",
        $sp,
        0
    );
    $openOrders = (int) val(
        "SELECT COUNT(*) FROM orders WHERE status IN ('pending','approved'){$sc}",
        $sp,
        0
    );

    $branch = ['mode' => $scope['mode'], 'id' => $scope['id'], 'name' => $scope['name']];
    if ($scope['mode'] === 'all') {
        $branch['branches'] = rows(
            "SELECT b.id, b.name, b.is_active,
                COALESCE((SELECT SUM(s.total_amount) FROM sales s WHERE s.branch_id=b.id AND s.sale_date=? AND s.is_void=0),0) sold,
                COALESCE((SELECT SUM(m.full_change) FROM stock_movements m WHERE m.branch_id=b.id),0) full_qty
             FROM branches b ORDER BY b.name",
            [$d]
        );
    }

    json_response([
        'branch' => $branch,
        'date' => $d,
        'sold' => money($sold),
        'cash' => money($methodTotals['cash']),
        'gcash' => money($methodTotals['gcash']),
        'maya' => money($methodTotals['maya']),
        'empties_owed' => $empties,
        'open_orders' => $openOrders,
        'stock' => $stockRows,
    ]);
}
