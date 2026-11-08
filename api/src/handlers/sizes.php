<?php
declare(strict_types=1);

function api_sizes_all(): void
{
    require_owner();
    $rows = rows('SELECT id, label, selling_price, valve_type, is_active FROM cylinder_sizes ORDER BY id');
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['selling_price'] = (float) $r['selling_price'];
        $r['is_active'] = (int) $r['is_active'];
    }
    unset($r);
    json_response($rows);
}

function api_sizes_create(): void
{
    $u = require_owner();
    $label = input_str('label', 'Size name', 1, 30);
    $price = input_decimal('price', 'Selling price', 0, 1000000);
    $valve = input_enum('valve_type', 'Valve type', ['snap_on', 'pol', 'both', 'unspecified'], false) ?: 'unspecified';
    if (val('SELECT id FROM cylinder_sizes WHERE label=?', [$label])) {
        fail('A cylinder size with that name already exists.', 409, 'duplicate');
    }
    $id = tx(function () use ($label, $price, $valve) {
        q('INSERT INTO cylinder_sizes (label, selling_price, valve_type) VALUES (?,?,?)', [$label, $price, $valve]);
        $id = (int) db()->lastInsertId();
        // Seed the lock row for every branch so stock locking always works.
        q('INSERT IGNORE INTO stock_locks (branch_id, size_id) SELECT id, ? FROM branches', [$id]);
        return $id;
    });
    audit($u, 'create', 'size', $id, null, ['label' => $label, 'price' => $price, 'valve_type' => $valve], null);
    json_response(['ok' => 1, 'id' => $id], 201);
}

function api_sizes_delete(array $params): void
{
    $u = require_owner();
    $id = (int) $params[0];
    $size = row('SELECT * FROM cylinder_sizes WHERE id=?', [$id]);
    if (!$size) {
        fail('Cylinder size not found.', 404, 'not_found');
    }
    // Refuse to hide a size that still has cylinders anywhere.
    $stock = (int) val(
        'SELECT COUNT(*) FROM (
           SELECT branch_id FROM stock_movements WHERE size_id=? GROUP BY branch_id
           HAVING SUM(full_change)<>0 OR SUM(empty_change)<>0 OR SUM(out_change)<>0
         ) x',
        [$id],
        0
    );
    $openOrders = (int) val("SELECT COUNT(*) FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE oi.size_id=? AND o.status IN ('pending','approved')", [$id], 0);
    if ($stock > 0 || $openOrders > 0) {
        fail('This size still has stock or open orders. Resolve those before removing it.', 409, 'size_in_use');
    }
    q('UPDATE cylinder_sizes SET is_active=0 WHERE id=?', [$id]);
    audit($u, 'deactivate', 'size', $id, ['is_active' => 1], ['is_active' => 0], null);
    ok(['ok' => 1]);
}
