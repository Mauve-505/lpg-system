<?php
declare(strict_types=1);

/** Owner-only audit trail browser. */
function api_audit_list(): void
{
    require_owner();
    $p = pagination();
    $where = '1=1';
    $params = [];

    if (($action = query_str('action', 50)) !== '') {
        $where .= ' AND a.action=?';
        $params[] = $action;
    }
    if (($entity = query_str('entity', 50)) !== '') {
        $where .= ' AND a.entity=?';
        $params[] = $entity;
    }
    if (($userId = query_int('user_id', null, 1)) !== null) {
        $where .= ' AND a.user_id=?';
        $params[] = $userId;
    }
    if (($branchId = query_int('branch_id', null, 1)) !== null) {
        $where .= ' AND a.branch_id=?';
        $params[] = $branchId;
    }
    if (($from = query_str('from', 10)) !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            fail('From date must use YYYY-MM-DD.', 422, 'validation_error', ['field' => 'from']);
        }
        $where .= ' AND a.created_at>=?';
        $params[] = $from . ' 00:00:00';
    }
    if (($to = query_str('to', 10)) !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            fail('To date must use YYYY-MM-DD.', 422, 'validation_error', ['field' => 'to']);
        }
        $where .= ' AND a.created_at<=?';
        $params[] = $to . ' 23:59:59';
    }
    if ($p['q'] !== '') {
        $where .= ' AND (a.entity_id LIKE ? OR u.name LIKE ? OR a.ip LIKE ?)';
        $like = '%' . $p['q'] . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $total = (int) val(
        'SELECT COUNT(*) FROM audit_log a LEFT JOIN users u ON u.id=a.user_id WHERE ' . $where,
        $params,
        0
    );
    $st = db()->prepare(
        'SELECT a.id, a.user_id, a.branch_id, a.action, a.entity, a.entity_id,
                a.old_values, a.new_values, a.ip, a.created_at,
                u.name user_name, u.username, b.name branch_name
         FROM audit_log a
         LEFT JOIN users u ON u.id=a.user_id
         LEFT JOIN branches b ON b.id=a.branch_id
         WHERE ' . $where . ' ORDER BY a.id DESC LIMIT ? OFFSET ?'
    );
    bind_page($st, $params, $p['per'], $p['offset']);
    $st->execute();
    $list = $st->fetchAll();
    foreach ($list as &$r) {
        $r['id'] = (int) $r['id'];
        $r['user_id'] = $r['user_id'] === null ? null : (int) $r['user_id'];
        $r['branch_id'] = $r['branch_id'] === null ? null : (int) $r['branch_id'];
        if ($r['old_values'] !== null) {
            $decoded = json_decode((string) $r['old_values'], true);
            $r['old_values'] = is_array($decoded) ? $decoded : null;
        }
        if ($r['new_values'] !== null) {
            $decoded = json_decode((string) $r['new_values'], true);
            $r['new_values'] = is_array($decoded) ? $decoded : null;
        }
    }
    unset($r);
    paginate_headers($total, $p['page'], $p['per']);
    json_response($list);
}
