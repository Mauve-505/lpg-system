<?php
declare(strict_types=1);

function api_adjustments_list(): void
{
    $u = require_owner();
    $scope = branch_scope($u);
    [$sc, $sp] = scope_sql($scope, 'a.branch_id');
    $p = pagination();
    $where = '1=1' . $sc;
    $params = $sp;
    if ($p['q'] !== '') {
        $where .= ' AND (a.reason LIKE ? OR a.note LIKE ?)';
        $like = '%' . $p['q'] . '%';
        $params[] = $like;
        $params[] = $like;
    }
    $total = (int) val('SELECT COUNT(*) FROM stock_adjustments a WHERE ' . $where, $params, 0);
    $st = db()->prepare(
        'SELECT a.*, z.label AS size, b.name branch_name, u.name created_by_name
         FROM stock_adjustments a JOIN cylinder_sizes z ON z.id=a.size_id JOIN branches b ON b.id=a.branch_id
         JOIN users u ON u.id=a.created_by
         WHERE ' . $where . ' ORDER BY a.adj_date DESC, a.id DESC LIMIT ? OFFSET ?'
    );
    bind_page($st, $params, $p['per'], $p['offset']);
    $st->execute();
    paginate_headers($total, $p['page'], $p['per']);
    json_response($st->fetchAll());
}

function api_adjustments_create(): void
{
    $u = require_owner();
    $scope = branch_scope($u, true);
    $branchId = (int) $scope['id'];
    require_active_branch($branchId);

    $date = input_date('date', 'Adjustment date', false) ?: today();
    $sizeId = input_int('size_id', 'Cylinder size', 1);
    if (!row('SELECT id FROM cylinder_sizes WHERE id=?', [$sizeId])) {
        fail('Unknown cylinder size.', 422, 'validation_error', ['field' => 'size_id']);
    }
    $reason = input_enum('reason', 'Reason', ['Opening count', 'Damaged', 'Lost', 'Correction', 'Other']);
    $note = input_str('note', 'Note', 0, 200, false);
    $full = input_int('full', 'Change in full cylinders', -100000, 100000, false) ?? 0;
    $empty = input_int('empty', 'Change in empty cylinders', -100000, 100000, false) ?? 0;
    if ($full === 0 && $empty === 0) {
        fail('Enter a non-zero change.', 422, 'validation_error', ['field' => 'full']);
    }

    $id = tx(function () use ($u, $branchId, $date, $sizeId, $reason, $note, $full, $empty) {
        lock_stock($branchId, [$sizeId]);
        assert_stock_after($branchId, $sizeId, $full, $empty, 0);
        q(
            'INSERT INTO stock_adjustments (branch_id, adj_date, size_id, reason, note, full_change, empty_change, created_by)
             VALUES (?,?,?,?,?,?,?,?)',
            [$branchId, $date, $sizeId, $reason, $note === '' ? null : $note, $full, $empty, (int) $u['id']]
        );
        $id = (int) db()->lastInsertId();
        movement($branchId, $date, $sizeId, 'adjustment', $id, $full, $empty, 0, (int) $u['id'], $reason);
        return $id;
    });

    audit($u, 'create', 'adjustment', $id, null, ['full' => $full, 'empty' => $empty, 'reason' => $reason, 'branch_id' => $branchId], $branchId);
    json_response(['ok' => 1, 'id' => $id], 201);
}

function api_adjustments_void(array $params): void
{
    $u = require_owner();
    $id = (int) $params[0];
    $reason = input_str('reason', 'Void reason', 3, 200, true);
    $branchId = tx(function () use ($u, $id, $reason) {
        $a = row('SELECT * FROM stock_adjustments WHERE id=? FOR UPDATE', [$id]);
        if (!$a) {
            fail('Adjustment not found.', 404, 'not_found');
        }
        if ((int) $a['is_void'] === 1) {
            fail('This adjustment is already void.', 409, 'already_void');
        }
        lock_stock((int) $a['branch_id'], [(int) $a['size_id']]);
        assert_stock_after((int) $a['branch_id'], (int) $a['size_id'], -(int) $a['full_change'], -(int) $a['empty_change'], 0);
        movement((int) $a['branch_id'], today(), (int) $a['size_id'], 'void', $id, -(int) $a['full_change'], -(int) $a['empty_change'], 0, (int) $u['id'], 'Void adjustment: ' . $reason);
        q('UPDATE stock_adjustments SET is_void=1, voided_at=?, voided_by=?, void_reason=? WHERE id=?', [now_iso(), (int) $u['id'], $reason, $id]);
        return (int) $a['branch_id'];
    });
    audit($u, 'void', 'adjustment', $id, ['is_void' => 0], ['is_void' => 1, 'reason' => $reason], $branchId);
    ok(['ok' => 1, 'voided' => true]);
}
