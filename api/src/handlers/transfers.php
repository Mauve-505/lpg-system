<?php
declare(strict_types=1);

/**
 * Inter-branch stock transfers.
 *
 *   requested -> approved -> in_transit -> received
 *                    \-> cancelled (requested/approved/in_transit)
 *
 * Source stock is decremented exactly once when shipped, destination stock is
 * incremented exactly once when received. Both operations lock the transfer row
 * and the branch/size stock lock rows, so retries and concurrency are safe.
 */

/** Branch-bound roles are tied to u.branch_id and cannot act across branches. */
function transfer_role_is_branch_scoped(array $u): bool
{
    return in_array($u['role'], ['branch_admin', 'staff', 'delivery'], true);
}

function transfer_items(int $transferId): array
{
    $items = rows(
        'SELECT i.id, i.size_id, i.qty_full, i.qty_empty, i.received_full, i.received_empty, z.label AS size
         FROM stock_transfer_items i JOIN cylinder_sizes z ON z.id=i.size_id
         WHERE i.transfer_id=? ORDER BY i.id',
        [$transferId]
    );
    foreach ($items as &$it) {
        $it['size_id'] = (int) $it['size_id'];
        $it['qty_full'] = (int) $it['qty_full'];
        $it['qty_empty'] = (int) $it['qty_empty'];
        $it['received_full'] = isset($it['received_full']) ? (int) $it['received_full'] : null;
        $it['received_empty'] = isset($it['received_empty']) ? (int) $it['received_empty'] : null;
    }
    unset($it);
    return $items;
}

function transfer_payload(array $t, ?array $items = null): array
{
    $items = $items ?? transfer_items((int) $t['id']);
    $shortageFull = 0;
    $shortageEmpty = 0;
    foreach ($items as $it) {
        if (isset($it['received_full'])) {
            $shortageFull += max(0, (int) $it['qty_full'] - (int) $it['received_full']);
        }
        if (isset($it['received_empty'])) {
            $shortageEmpty += max(0, (int) $it['qty_empty'] - (int) $it['received_empty']);
        }
    }
    return [
        'id' => (int) $t['id'],
        'transfer_no' => $t['transfer_no'],
        'from_branch_id' => (int) $t['from_branch_id'],
        'from_branch' => $t['from_branch'] ?? null,
        'to_branch_id' => (int) $t['to_branch_id'],
        'to_branch' => $t['to_branch'] ?? null,
        'status' => $t['status'],
        'notes' => $t['notes'],
        'requested_by' => $t['requested_by_name'] ?? null,
        'requested_at' => $t['requested_at'],
        'approved_at' => $t['approved_at'],
        'shipped_at' => $t['shipped_at'],
        'received_at' => $t['received_at'],
        'cancelled_at' => $t['cancelled_at'],
        'cancel_reason' => $t['cancel_reason'],
        'shortage_note' => $t['shortage_note'] ?? null,
        'items' => $items,
        'total_full' => array_sum(array_column($items, 'qty_full')),
        'total_empty' => array_sum(array_column($items, 'qty_empty')),
        'shortage_full' => $shortageFull,
        'shortage_empty' => $shortageEmpty,
    ];
}

function transfer_load(int $id): array
{
    $t = row(
        'SELECT t.*, bf.name from_branch, bt.name to_branch, u.name requested_by_name
         FROM stock_transfers t
         JOIN branches bf ON bf.id=t.from_branch_id
         JOIN branches bt ON bt.id=t.to_branch_id
         JOIN users u ON u.id=t.requested_by
         WHERE t.id=?',
        [$id]
    );
    if (!$t) {
        fail('Transfer not found.', 404, 'not_found');
    }
    return $t;
}

function transfer_can_view(array $u, array $t): bool
{
    if ($u['role'] === 'owner') {
        return true;
    }
    return (int) $u['branch_id'] === (int) $t['from_branch_id']
        || (int) $u['branch_id'] === (int) $t['to_branch_id'];
}

function require_transfer_view(array $u, array $t): void
{
    if (!transfer_can_view($u, $t)) {
        fail('You do not have access to that transfer.', 403, 'branch_forbidden');
    }
}

function transfer_require_source(array $u, array $t): void
{
    if ($u['role'] === 'owner') {
        return;
    }
    if ((int) $u['branch_id'] !== (int) $t['from_branch_id']) {
        fail('Only staff at the sending branch can do that.', 403, 'branch_forbidden');
    }
}

function transfer_require_destination(array $u, array $t): void
{
    if ($u['role'] === 'owner') {
        return;
    }
    if ((int) $u['branch_id'] !== (int) $t['to_branch_id']) {
        fail('Only staff at the receiving branch can do that.', 403, 'branch_forbidden');
    }
}

function api_transfers_list(): void
{
    $u = require_order_access();
    $p = pagination();
    $where = '1=1';
    $params = [];

    // Branch-bound roles see transfers touching their own branch, regardless
    // of any client-supplied branch_id. The owner may filter by branch.
    if (transfer_role_is_branch_scoped($u)) {
        $where .= ' AND (t.from_branch_id=? OR t.to_branch_id=?)';
        $params[] = (int) $u['branch_id'];
        $params[] = (int) $u['branch_id'];
    } elseif (($bid = query_int('branch_id', null, 1)) !== null) {
        $where .= ' AND (t.from_branch_id=? OR t.to_branch_id=?)';
        $params[] = $bid;
        $params[] = $bid;
    }
    if (($status = query_str('status', 20)) !== '') {
        if (!in_array($status, ['requested', 'approved', 'in_transit', 'received', 'cancelled'], true)) {
            fail('Invalid status filter.', 422, 'validation_error', ['field' => 'status']);
        }
        $where .= ' AND t.status=?';
        $params[] = $status;
    }
    if ($p['q'] !== '') {
        $where .= ' AND (t.transfer_no LIKE ? OR bf.name LIKE ? OR bt.name LIKE ?)';
        $like = '%' . $p['q'] . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $total = (int) val(
        'SELECT COUNT(*) FROM stock_transfers t
         JOIN branches bf ON bf.id=t.from_branch_id JOIN branches bt ON bt.id=t.to_branch_id
         WHERE ' . $where,
        $params,
        0
    );
    $st = db()->prepare(
        'SELECT t.*, bf.name from_branch, bt.name to_branch, u.name requested_by_name
         FROM stock_transfers t
         JOIN branches bf ON bf.id=t.from_branch_id
         JOIN branches bt ON bt.id=t.to_branch_id
         JOIN users u ON u.id=t.requested_by
         WHERE ' . $where . " ORDER BY FIELD(t.status,'requested','approved','in_transit','received','cancelled'), t.id DESC
         LIMIT ? OFFSET ?"
    );
    bind_page($st, $params, $p['per'], $p['offset']);
    $st->execute();
    $list = $st->fetchAll();
    $items = [];
    if ($list) {
        $ids = array_map(static fn($t) => (int) $t['id'], $list);
        $in = implode(',', array_fill(0, count($ids), '?'));
        foreach (rows(
            "SELECT i.*, z.label AS size FROM stock_transfer_items i
             JOIN cylinder_sizes z ON z.id=i.size_id WHERE i.transfer_id IN ($in) ORDER BY i.id",
            $ids
        ) as $x) {
            $x['size_id'] = (int) $x['size_id'];
            $x['qty_full'] = (int) $x['qty_full'];
            $x['qty_empty'] = (int) $x['qty_empty'];
            $x['received_full'] = isset($x['received_full']) ? (int) $x['received_full'] : null;
            $x['received_empty'] = isset($x['received_empty']) ? (int) $x['received_empty'] : null;
            $items[(int) $x['transfer_id']][] = $x;
        }
    }
    paginate_headers($total, $p['page'], $p['per']);
    json_response(array_map(static fn($t) => transfer_payload($t, $items[(int) $t['id']] ?? []), $list));
}

function api_transfer_show(array $params): void
{
    $u = require_order_access();
    $t = transfer_load((int) $params[0]);
    require_transfer_view($u, $t);
    json_response(transfer_payload($t));
}

function api_transfers_create(): void
{
    $u = require_staff_or_owner();
    $fromId = input_int('from_branch_id', 'Sending branch', 1);
    $toId = input_int('to_branch_id', 'Receiving branch', 1);
    if (transfer_role_is_branch_scoped($u)) {
        if ($fromId !== (int) $u['branch_id']) {
            fail('You can only request stock from your own branch.', 403, 'branch_forbidden');
        }
    }
    if ($fromId === $toId) {
        fail('Choose two different branches.', 422, 'validation_error', ['field' => 'to_branch_id']);
    }
    require_active_branch((int) $fromId);
    require_active_branch((int) $toId);

    $notes = input_str('notes', 'Notes', 0, 200, false);
    $idempotency = input_str('idempotency_key', 'Idempotency key', 0, 80, false);

    if ($idempotency !== '') {
        $existing = row('SELECT * FROM stock_transfers WHERE idempotency_key=?', [$idempotency]);
        if ($existing) {
            if ((int) $existing['from_branch_id'] !== $fromId || (int) $existing['to_branch_id'] !== $toId) {
                fail('That idempotency key was already used for a different transfer.', 409, 'idempotency_conflict');
            }
            json_response(transfer_payload(transfer_load((int) $existing['id'])), 200);
        }
    }

    $raw = body_value('items');
    if (!is_array($raw) || count($raw) === 0) {
        fail('Add at least one cylinder size.', 422, 'validation_error', ['field' => 'items']);
    }
    if (count($raw) > 20) {
        fail('A transfer can have at most 20 line items.', 422, 'validation_error', ['field' => 'items']);
    }
    $clean = [];
    foreach ($raw as $it) {
        if (!is_array($it)) {
            fail('Invalid transfer item.', 422, 'validation_error', ['field' => 'items']);
        }
        $sid = isset($it['size_id']) && is_numeric($it['size_id']) ? (int) $it['size_id'] : 0;
        $full = isset($it['qty_full']) && is_numeric($it['qty_full']) ? (float) $it['qty_full']
            : (isset($it['full']) && is_numeric($it['full']) ? (float) $it['full'] : 0.0);
        $empty = isset($it['qty_empty']) && is_numeric($it['qty_empty']) ? (float) $it['qty_empty']
            : (isset($it['empty']) && is_numeric($it['empty']) ? (float) $it['empty'] : 0.0);
        if ($sid < 1 || !row('SELECT id FROM cylinder_sizes WHERE id=? AND is_active=1', [$sid])) {
            fail('Unknown cylinder size.', 422, 'validation_error', ['field' => 'items']);
        }
        if (floor($full) !== $full || $full < 0 || $full > 100000 || floor($empty) !== $empty || $empty < 0 || $empty > 100000) {
            fail('Quantities must be whole numbers between 0 and 100000.', 422, 'validation_error', ['field' => 'items']);
        }
        if ($full + $empty <= 0) {
            fail('Each line needs a full or empty quantity.', 422, 'validation_error', ['field' => 'items']);
        }
        if (isset($clean[$sid])) {
            fail('Each size may only appear once per transfer.', 422, 'validation_error', ['field' => 'items']);
        }
        $clean[$sid] = ['size_id' => $sid, 'qty_full' => (int) $full, 'qty_empty' => (int) $empty];
    }

    try {
        $id = tx(function () use ($u, $fromId, $toId, $notes, $idempotency, $clean) {
            $temporaryNo = 'TMP-' . bin2hex(random_bytes(8));
            q(
                'INSERT INTO stock_transfers (transfer_no, from_branch_id, to_branch_id, status, notes, idempotency_key, requested_by)
                 VALUES (?,?,?,\'requested\',?,?,?)',
                [$temporaryNo, $fromId, $toId, $notes === '' ? null : $notes, $idempotency === '' ? null : $idempotency, (int) $u['id']]
            );
            $id = (int) db()->lastInsertId();
            q('UPDATE stock_transfers SET transfer_no=? WHERE id=?', ['TRF-' . date('Ymd') . '-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT), $id]);
            foreach ($clean as $it) {
                q(
                    'INSERT INTO stock_transfer_items (transfer_id, size_id, qty_full, qty_empty) VALUES (?,?,?,?)',
                    [$id, $it['size_id'], $it['qty_full'], $it['qty_empty']]
                );
            }
            return $id;
        });
    } catch (PDOException $e) {
        if ($e->getCode() === '23000' && $idempotency !== '') {
            $existing = row('SELECT * FROM stock_transfers WHERE idempotency_key=?', [$idempotency]);
            if ($existing) {
                json_response(transfer_payload(transfer_load((int) $existing['id'])), 200);
            }
        }
        throw $e;
    }

    audit($u, 'create', 'transfer', $id, null, ['from' => $fromId, 'to' => $toId, 'items' => $clean], $fromId);
    json_response(transfer_payload(transfer_load($id)), 201);
}

function api_transfer_approve(array $params): void
{
    $u = require_staff_or_owner();
    $id = (int) $params[0];
    tx(function () use ($u, $id) {
        $t = row('SELECT * FROM stock_transfers WHERE id=? FOR UPDATE', [$id]);
        if (!$t) {
            fail('Transfer not found.', 404, 'not_found');
        }
        transfer_require_source($u, $t);
        if ($t['status'] === 'approved' || $t['status'] === 'in_transit' || $t['status'] === 'received') {
            return;
        }
        if ($t['status'] !== 'requested') {
            fail('Only requested transfers can be approved.', 409, 'transfer_locked');
        }
        require_active_branch((int) $t['from_branch_id']);
        require_active_branch((int) $t['to_branch_id']);
        q("UPDATE stock_transfers SET status='approved', approved_by=?, approved_at=? WHERE id=?", [(int) $u['id'], now_iso(), $id]);
    });
    audit($u, 'approve', 'transfer', $id, ['status' => 'requested'], ['status' => 'approved'], null);
    json_response(transfer_payload(transfer_load($id)));
}

function api_transfer_ship(array $params): void
{
    $u = require_staff_or_owner();
    $id = (int) $params[0];
    tx(function () use ($u, $id) {
        $t = row('SELECT * FROM stock_transfers WHERE id=? FOR UPDATE', [$id]);
        if (!$t) {
            fail('Transfer not found.', 404, 'not_found');
        }
        transfer_require_source($u, $t);
        // Retries after a lost response must not post the ledger twice.
        if ($t['status'] === 'in_transit' || $t['status'] === 'received') {
            return;
        }
        if ($t['status'] !== 'approved') {
            fail('Only approved transfers can be shipped.', 409, 'transfer_locked');
        }
        $items = rows('SELECT * FROM stock_transfer_items WHERE transfer_id=?', [$id]);
        $sizeIds = array_map(static fn($x) => (int) $x['size_id'], $items);
        lock_stock((int) $t['from_branch_id'], $sizeIds);
        foreach ($items as $it) {
            assert_stock_after((int) $t['from_branch_id'], (int) $it['size_id'], -(int) $it['qty_full'], -(int) $it['qty_empty'], 0);
        }
        foreach ($items as $it) {
            movement(
                (int) $t['from_branch_id'],
                today(),
                (int) $it['size_id'],
                'transfer',
                $id,
                -(int) $it['qty_full'],
                -(int) $it['qty_empty'],
                0,
                (int) $u['id'],
                'Transfer out ' . $t['transfer_no']
            );
        }
        q("UPDATE stock_transfers SET status='in_transit', shipped_by=?, shipped_at=? WHERE id=?", [(int) $u['id'], now_iso(), $id]);
    });
    audit($u, 'ship', 'transfer', $id, ['status' => 'approved'], ['status' => 'in_transit'], null);
    json_response(transfer_payload(transfer_load($id)));
}

function api_transfer_receive(array $params): void
{
    $u = require_staff_or_owner();
    $id = (int) $params[0];
    $callerNote = input_str('shortage_note', 'Shortage note', 0, 200, false);

    // Optional per-size actual counts. Sizes not listed are treated as fully
    // received; a missing count on a listed size means zero received.
    $rawReceived = body_value('items');
    $reported = [];
    if ($rawReceived !== null) {
        if (!is_array($rawReceived) || count($rawReceived) > 20) {
            fail('Invalid received items.', 422, 'validation_error', ['field' => 'items']);
        }
        foreach ($rawReceived as $it) {
            if (!is_array($it)) {
                fail('Invalid received item.', 422, 'validation_error', ['field' => 'items']);
            }
            $sid = isset($it['size_id']) && is_numeric($it['size_id']) ? (int) $it['size_id'] : 0;
            $full = isset($it['received_full']) && is_numeric($it['received_full']) ? (float) $it['received_full']
                : (isset($it['full']) && is_numeric($it['full']) ? (float) $it['full'] : 0.0);
            $empty = isset($it['received_empty']) && is_numeric($it['received_empty']) ? (float) $it['received_empty']
                : (isset($it['empties']) && is_numeric($it['empties']) ? (float) $it['empties'] : 0.0);
            if ($sid < 1) {
                fail('Unknown cylinder size.', 422, 'validation_error', ['field' => 'items']);
            }
            if (floor($full) !== $full || $full < 0 || $full > 100000 || floor($empty) !== $empty || $empty < 0 || $empty > 100000) {
                fail('Quantities must be whole numbers between 0 and 100000.', 422, 'validation_error', ['field' => 'items']);
            }
            if (isset($reported[$sid])) {
                fail('Each size may only appear once per transfer.', 422, 'validation_error', ['field' => 'items']);
            }
            $reported[$sid] = ['full' => (int) $full, 'empty' => (int) $empty];
        }
    }

    $result = tx(function () use ($u, $id, $reported, $callerNote) {
        $t = row('SELECT * FROM stock_transfers WHERE id=? FOR UPDATE', [$id]);
        if (!$t) {
            fail('Transfer not found.', 404, 'not_found');
        }
        transfer_require_destination($u, $t);
        if ($t['status'] === 'received') {
            return ['shortages' => [], 'shortage_note' => null]; // idempotent retry
        }
        if ($t['status'] !== 'in_transit') {
            fail('Only in-transit transfers can be received.', 409, 'transfer_locked');
        }
        $items = rows(
            'SELECT i.*, z.label AS size FROM stock_transfer_items i
             JOIN cylinder_sizes z ON z.id=i.size_id WHERE i.transfer_id=? ORDER BY i.id',
            [$id]
        );
        $shippedBySize = [];
        foreach ($items as $it) {
            $shippedBySize[(int) $it['size_id']] = true;
        }
        foreach ($reported as $sid => $counts) {
            if (!isset($shippedBySize[$sid])) {
                fail('Received item does not match a shipped size.', 422, 'validation_error', ['field' => 'items']);
            }
        }

        $actual = [];
        $shortBySize = [];
        $shortages = [];
        $summary = [];
        foreach ($items as $it) {
            $sid = (int) $it['size_id'];
            $qtyFull = (int) $it['qty_full'];
            $qtyEmpty = (int) $it['qty_empty'];
            $receivedFull = isset($reported[$sid]) ? $reported[$sid]['full'] : $qtyFull;
            $receivedEmpty = isset($reported[$sid]) ? $reported[$sid]['empty'] : $qtyEmpty;
            if ($receivedFull > $qtyFull || $receivedEmpty > $qtyEmpty) {
                fail('Received quantities cannot exceed shipped quantities.', 422, 'validation_error', ['field' => 'items']);
            }
            $actual[$sid] = ['full' => $receivedFull, 'empty' => $receivedEmpty];
            $shortFull = $qtyFull - $receivedFull;
            $shortEmpty = $qtyEmpty - $receivedEmpty;
            if ($shortFull > 0 || $shortEmpty > 0) {
                $bits = [];
                if ($shortFull > 0) {
                    $bits[] = $shortFull . ' full';
                }
                if ($shortEmpty > 0) {
                    $bits[] = $shortEmpty . ' empty';
                }
                $shortBySize[$sid] = ['full' => $shortFull, 'empty' => $shortEmpty];
                $summary[] = implode(', ', $bits) . ' (' . $it['size'] . ')';
                $shortages[] = [
                    'size_id' => $sid,
                    'size' => $it['size'],
                    'qty_full' => $qtyFull,
                    'qty_empty' => $qtyEmpty,
                    'received_full' => $receivedFull,
                    'received_empty' => $receivedEmpty,
                    'short_full' => $shortFull,
                    'short_empty' => $shortEmpty,
                ];
            }
        }

        $sizeIds = array_map(static fn($x) => (int) $x['size_id'], $items);
        lock_stock((int) $t['to_branch_id'], $sizeIds);
        foreach ($items as $it) {
            $sid = (int) $it['size_id'];
            assert_stock_after((int) $t['to_branch_id'], $sid, $actual[$sid]['full'], $actual[$sid]['empty'], 0);
        }
        foreach ($items as $it) {
            $sid = (int) $it['size_id'];
            $bits = [];
            if (isset($shortBySize[$sid])) {
                if ($shortBySize[$sid]['full'] > 0) {
                    $bits[] = $shortBySize[$sid]['full'] . ' full';
                }
                if ($shortBySize[$sid]['empty'] > 0) {
                    $bits[] = $shortBySize[$sid]['empty'] . ' empty';
                }
            }
            $note = 'Transfer in ' . $t['transfer_no'] . ($bits ? ' (short ' . implode(', ', $bits) . ')' : '');
            movement(
                (int) $t['to_branch_id'],
                today(),
                $sid,
                'transfer',
                $id,
                $actual[$sid]['full'],
                $actual[$sid]['empty'],
                0,
                (int) $u['id'],
                $note
            );
        }
        foreach ($items as $it) {
            $sid = (int) $it['size_id'];
            q(
                'UPDATE stock_transfer_items SET received_full=?, received_empty=? WHERE id=?',
                [$actual[$sid]['full'], $actual[$sid]['empty'], (int) $it['id']]
            );
        }
        $storedNote = null;
        if ($shortages) {
            $storedNote = $callerNote !== '' ? $callerNote : 'Short ' . implode('; ', $summary);
            $storedNote = mb_substr($storedNote, 0, 200);
        }
        q(
            "UPDATE stock_transfers SET status='received', received_by=?, received_at=?, shortage_note=? WHERE id=?",
            [(int) $u['id'], now_iso(), $storedNote, $id]
        );
        return ['shortages' => $shortages, 'shortage_note' => $storedNote];
    });

    $auditNew = ['status' => 'received', 'shortages' => $result['shortages']];
    if ($result['shortage_note'] !== null) {
        $auditNew['shortage_note'] = $result['shortage_note'];
    }
    audit($u, 'receive', 'transfer', $id, ['status' => 'in_transit'], $auditNew, null);
    json_response(transfer_payload(transfer_load($id)));
}

function api_transfer_cancel(array $params): void
{
    $u = require_staff_or_owner();
    $id = (int) $params[0];
    $reason = input_str('reason', 'Cancellation reason', 3, 200);
    $restored = tx(function () use ($u, $id, $reason) {
        $t = row('SELECT * FROM stock_transfers WHERE id=? FOR UPDATE', [$id]);
        if (!$t) {
            fail('Transfer not found.', 404, 'not_found');
        }
        // Cancelling in transit returns stock to the sender, so only the source
        // branch may do it. Earlier stages keep the existing view-level access.
        if ($t['status'] === 'in_transit') {
            transfer_require_source($u, $t);
        } else {
            require_transfer_view($u, $t);
        }
        if ($t['status'] === 'cancelled') {
            return false; // idempotent retry
        }
        if (!in_array($t['status'], ['requested', 'approved', 'in_transit'], true)) {
            fail('Only requested, approved or in-transit transfers can be cancelled. Cancelling in transit restores stock at the sending branch.', 409, 'transfer_locked');
        }
        if ($t['status'] === 'in_transit') {
            // Shipping already reduced source stock; reverse that exact ledger.
            $items = rows('SELECT * FROM stock_transfer_items WHERE transfer_id=?', [$id]);
            $sizeIds = array_map(static fn($x) => (int) $x['size_id'], $items);
            lock_stock((int) $t['from_branch_id'], $sizeIds);
            foreach ($items as $it) {
                assert_stock_after((int) $t['from_branch_id'], (int) $it['size_id'], (int) $it['qty_full'], (int) $it['qty_empty'], 0);
            }
            foreach ($items as $it) {
                movement(
                    (int) $t['from_branch_id'],
                    today(),
                    (int) $it['size_id'],
                    'transfer',
                    $id,
                    (int) $it['qty_full'],
                    (int) $it['qty_empty'],
                    0,
                    (int) $u['id'],
                    'Transfer cancelled ' . $t['transfer_no']
                );
            }
        }
        q(
            "UPDATE stock_transfers SET status='cancelled', cancelled_by=?, cancelled_at=?, cancel_reason=? WHERE id=?",
            [(int) $u['id'], now_iso(), $reason, $id]
        );
        return $t['status'] === 'in_transit';
    });
    audit(
        $u,
        'cancel',
        'transfer',
        $id,
        ['status' => $restored ? 'in_transit' : 'requested_or_approved'],
        ['status' => 'cancelled', 'reason' => $reason, 'source_stock_restored' => $restored],
        null
    );
    json_response(transfer_payload(transfer_load($id)));
}
