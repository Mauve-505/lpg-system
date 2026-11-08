<?php
declare(strict_types=1);

function customer_payload(array $c, ?int $branchId): array
{
    $bal = customer_balance((int) $c['id'], $branchId);
    return [
        'id' => (int) $c['id'],
        'branch_id' => $c['branch_id'] === null ? null : (int) $c['branch_id'],
        'name' => $c['name'],
        'contact' => $c['contact'],
        'address' => $c['address'],
        'credit_limit' => $c['credit_limit'] === null ? null : (float) $c['credit_limit'],
        'is_reseller' => (int) $c['is_reseller'],
        'reseller_status' => $c['reseller_status'],
        'is_active' => (int) $c['is_active'],
        'username' => $c['username'] ?? null,
        'balance' => $bal['balance'],
        'empties_owed' => $bal['empties_owed'],
    ];
}

/**
 * Staff may manage a customer when it is their home-branch customer or when
 * the customer has transacted with the staff member's branch. The owner may
 * manage every customer. Customer records are global: an allowed update
 * changes the contact/credit details that every branch sees.
 */
function can_manage_customer(array $u, array $c): bool
{
    if ($u['role'] === 'owner') {
        return true;
    }
    if ($u['role'] !== 'staff') {
        return false;
    }
    $branchId = (int) $u['branch_id'];
    if ($c['branch_id'] !== null && (int) $c['branch_id'] === $branchId) {
        return true;
    }
    return (int) val(
        'SELECT (SELECT COUNT(*) FROM sales WHERE customer_id=? AND branch_id=?)
              + (SELECT COUNT(*) FROM orders WHERE customer_id=? AND branch_id=?)
              + (SELECT COUNT(*) FROM payments WHERE customer_id=? AND branch_id=?)',
        [(int) $c['id'], $branchId, (int) $c['id'], $branchId, (int) $c['id'], $branchId],
        0
    ) > 0;
}

function api_customers_list(): void
{
    $u = require_staff_or_owner();
    $scope = branch_scope($u);
    $p = pagination();
    $branchId = $scope['mode'] === 'branch' ? (int) $scope['id'] : null;

    $where = '1=1';
    $params = [];
    if (query_str('include_inactive', 2) !== '1' || $u['role'] !== 'owner') {
        $where .= ' AND c.is_active=1';
    }
    if ($p['q'] !== '') {
        $where .= ' AND (c.name LIKE ? OR c.contact LIKE ? OR c.address LIKE ?)';
        $like = '%' . $p['q'] . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    // Staff only see customers who belong to their branch or have transacted
    // with it. Owner sees all.
    if ($u['role'] === 'staff') {
        $where .= ' AND (c.branch_id=?
                     OR EXISTS (SELECT 1 FROM sales s WHERE s.customer_id=c.id AND s.branch_id=?)
                     OR EXISTS (SELECT 1 FROM orders o WHERE o.customer_id=c.id AND o.branch_id=?)
                     OR EXISTS (SELECT 1 FROM payments pm WHERE pm.customer_id=c.id AND pm.branch_id=?))';
        $params[] = (int) $u['branch_id'];
        $params[] = (int) $u['branch_id'];
        $params[] = (int) $u['branch_id'];
        $params[] = (int) $u['branch_id'];
    }

    $total = (int) val('SELECT COUNT(*) FROM customers c WHERE ' . $where, $params, 0);
    $st = db()->prepare(
        'SELECT c.*, (SELECT username FROM users u WHERE u.customer_id=c.id ORDER BY u.id LIMIT 1) username
         FROM customers c WHERE ' . $where . ' ORDER BY c.name LIMIT ? OFFSET ?'
    );
    bind_page($st, $params, $p['per'], $p['offset']);
    $st->execute();
    $list = $st->fetchAll();
    paginate_headers($total, $p['page'], $p['per']);
    json_response(array_map(static fn($c) => customer_payload($c, $branchId), $list));
}

function api_customers_create(): void
{
    $u = require_staff_or_owner();
    $scope = branch_scope($u, true);
    $name = input_str('name', 'Customer name', 2, 100);
    $contact = input_str('contact', 'Contact number', 0, 50, false);
    $address = input_str('address', 'Address', 0, 200, false);
    $creditLimit = input_decimal('credit_limit', 'Credit limit', 0, 10000000, false);
    $isReseller = input_bool('is_reseller', false);
    $resellerStatus = input_enum('reseller_status', 'Reseller status', ['none', 'pending', 'approved', 'rejected'], false) ?: 'none';
    $branchId = (int) $scope['id'];

    q(
        'INSERT INTO customers (branch_id, name, contact, address, credit_limit, is_reseller, reseller_status) VALUES (?,?,?,?,?,?,?)',
        [$branchId, $name, $contact === '' ? null : $contact, $address === '' ? null : $address, $creditLimit, $isReseller ? 1 : 0, $resellerStatus]
    );
    $id = (int) db()->lastInsertId();
    audit($u, 'create', 'customer', $id, null, ['name' => $name, 'branch_id' => $branchId], $branchId);
    json_response(['ok' => 1, 'id' => $id], 201);
}

function api_customers_update(array $params): void
{
    $u = require_staff_or_owner();
    $id = (int) $params[0];
    $c = row('SELECT * FROM customers WHERE id=?', [$id]);
    if (!$c) {
        fail('Customer not found.', 404, 'not_found');
    }
    if (!can_manage_customer($u, $c)) {
        fail('You do not have access to that customer.', 403, 'branch_forbidden');
    }
    $name = input_str('name', 'Customer name', 2, 100);
    $contact = input_str('contact', 'Contact number', 0, 50, false);
    $address = input_str('address', 'Address', 0, 200, false);
    // Patch semantics: only touch the credit limit when the field was sent.
    $body = read_json_body();
    $creditLimit = array_key_exists('credit_limit', $body)
        ? input_decimal('credit_limit', 'Credit limit', 0, 10000000, false)
        : ($c['credit_limit'] === null ? null : (float) $c['credit_limit']);
    $isReseller = input_bool('is_reseller', (bool) $c['is_reseller']);
    $resellerStatus = input_enum('reseller_status', 'Reseller status', ['none', 'pending', 'approved', 'rejected'], false) ?: $c['reseller_status'];

    // Only the owner may move a customer between home branches. Staff keep the
    // existing link and can only edit customers already visible to them.
    $homeBranchId = $c['branch_id'] === null ? null : (int) $c['branch_id'];
    if ($u['role'] === 'owner' && array_key_exists('branch_id', $body)) {
        $homeBranchId = input_int('branch_id', 'Branch', 1, null, false);
        if ($homeBranchId !== null) {
            require_branch_access($u, (int) $homeBranchId, true);
        }
    }

    q(
        'UPDATE customers SET branch_id=?, name=?, contact=?, address=?, credit_limit=?, is_reseller=?, reseller_status=? WHERE id=?',
        [$homeBranchId, $name, $contact === '' ? null : $contact, $address === '' ? null : $address, $creditLimit, $isReseller ? 1 : 0, $resellerStatus, $id]
    );
    audit(
        $u,
        'update',
        'customer',
        $id,
        ['branch_id' => $c['branch_id'], 'name' => $c['name'], 'contact' => $c['contact'], 'address' => $c['address']],
        ['branch_id' => $homeBranchId, 'name' => $name, 'contact' => $contact, 'address' => $address],
        $homeBranchId
    );
    json_response(['ok' => 1]);
}

function api_customers_delete(array $params): void
{
    $u = require_staff_or_owner();
    $id = (int) $params[0];
    $c = row('SELECT * FROM customers WHERE id=?', [$id]);
    if (!$c) {
        fail('Customer not found.', 404, 'not_found');
    }
    if (!can_manage_customer($u, $c)) {
        fail('You do not have access to that customer.', 403, 'branch_forbidden');
    }
    if ((int) $c['is_active'] === 1 && (int) val('SELECT COUNT(*) FROM orders WHERE customer_id=? AND status IN (\'pending\',\'approved\')', [$id], 0) > 0) {
        fail('This customer has open orders. Resolve or cancel them first.', 409, 'open_orders');
    }
    tx(function () use ($id, $u) {
        q('UPDATE customers SET is_active=0 WHERE id=?', [$id]);
        // Disabling the customer also disables every login attached to it.
        q('UPDATE users SET is_active=0, session_version=session_version+1 WHERE customer_id=?', [$id]);
    });
    audit($u, 'deactivate', 'customer', $id, ['is_active' => 1], ['is_active' => 0], $c['branch_id'] === null ? null : (int) $c['branch_id']);
    ok(['ok' => 1]);
}
