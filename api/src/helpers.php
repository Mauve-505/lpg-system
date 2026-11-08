<?php
declare(strict_types=1);

/** An expected API error. The central dispatcher turns this into JSON. */
class ApiException extends RuntimeException
{
    public int $status;
    public string $errorCode;
    public array $extra;

    public function __construct(string $message, int $status = 400, string $errorCode = 'bad_request', array $extra = [])
    {
        parent::__construct($message);
        $this->status = $status;
        $this->errorCode = $errorCode;
        $this->extra = $extra;
    }
}

function json_response($data, int $status = 200, array $headers = []): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        header('Pragma: no-cache');
        foreach ($headers as $k => $v) {
            header($k . ': ' . $v);
        }
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function ok($data = ['ok' => 1], int $status = 200): void
{
    json_response($data, $status);
}

function fail(string $message, int $status = 400, string $code = 'bad_request', array $extra = []): void
{
    throw new ApiException($message, $status, $code, $extra);
}

function now_iso(): string
{
    return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
}

function today(): string
{
    return (new DateTimeImmutable('now'))->format('Y-m-d');
}

function client_ip(): string
{
    // Never trust X-Forwarded-For unless a trusted proxy is configured.
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return substr((string) $ip, 0, 45);
}

function read_json_body(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $max = (int) config('security.max_body_bytes', 1048576);
    $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len > $max) {
        fail('Request body is too large.', 413, 'payload_too_large');
    }
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        $cached = [];
        return $cached;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        fail('Request body must be valid JSON.', 400, 'invalid_json');
    }
    $cached = $data;
    return $cached;
}

function body_value(string $key, $default = null)
{
    $body = read_json_body();
    return array_key_exists($key, $body) ? $body[$key] : $default;
}

function input_str(string $key, string $label, int $min = 1, int $max = 200, bool $required = true, ?string $pattern = null): string
{
    $value = body_value($key);
    if ($value === null || $value === '') {
        if ($required) {
            fail($label . ' is required.', 422, 'validation_error', ['field' => $key]);
        }
        return '';
    }
    if (!is_string($value) && !is_numeric($value)) {
        fail($label . ' must be text.', 422, 'validation_error', ['field' => $key]);
    }
    $value = trim((string) $value);
    if (strpos($value, "\0") !== false) {
        fail($label . ' contains an invalid character.', 422, 'validation_error', ['field' => $key]);
    }
    $length = mb_strlen($value);
    if ($length < $min) {
        fail($label . ' is too short.', 422, 'validation_error', ['field' => $key]);
    }
    if ($length > $max) {
        fail($label . ' is too long (maximum ' . $max . ' characters).', 422, 'validation_error', ['field' => $key]);
    }
    if ($pattern !== null && !preg_match($pattern, $value)) {
        fail($label . ' has an invalid format.', 422, 'validation_error', ['field' => $key]);
    }
    return $value;
}

function input_int(string $key, string $label, ?int $min = null, ?int $max = null, bool $required = true): ?int
{
    $value = body_value($key);
    if ($value === null || $value === '') {
        if ($required) {
            fail($label . ' is required.', 422, 'validation_error', ['field' => $key]);
        }
        return null;
    }
    if (is_bool($value) || !is_numeric($value)) {
        fail($label . ' must be a whole number.', 422, 'validation_error', ['field' => $key]);
    }
    $n = (float) $value;
    if (floor($n) !== $n) {
        fail($label . ' must be a whole number.', 422, 'validation_error', ['field' => $key]);
    }
    $n = (int) $n;
    if ($min !== null && $n < $min) {
        fail($label . ' must be at least ' . $min . '.', 422, 'validation_error', ['field' => $key]);
    }
    if ($max !== null && $n > $max) {
        fail($label . ' must be at most ' . $max . '.', 422, 'validation_error', ['field' => $key]);
    }
    return $n;
}

function input_decimal(string $key, string $label, float $min = 0, ?float $max = null, bool $required = true): ?float
{
    $value = body_value($key);
    if ($value === null || $value === '') {
        if ($required) {
            fail($label . ' is required.', 422, 'validation_error', ['field' => $key]);
        }
        return null;
    }
    if (is_bool($value) || !is_numeric($value)) {
        fail($label . ' must be a number.', 422, 'validation_error', ['field' => $key]);
    }
    $n = round((float) $value, 2);
    if (!is_finite($n) || $n < $min) {
        fail($label . ' must be at least ' . number_format($min, 2) . '.', 422, 'validation_error', ['field' => $key]);
    }
    if ($max !== null && $n > $max) {
        fail($label . ' is too large.', 422, 'validation_error', ['field' => $key]);
    }
    return $n;
}

function input_date(string $key, string $label, bool $required = true): ?string
{
    $value = body_value($key);
    if ($value === null || $value === '') {
        if ($required) {
            fail($label . ' is required.', 422, 'validation_error', ['field' => $key]);
        }
        return null;
    }
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        fail($label . ' must use YYYY-MM-DD.', 422, 'validation_error', ['field' => $key]);
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$d || $d->format('Y-m-d') !== $value) {
        fail($label . ' is not a valid date.', 422, 'validation_error', ['field' => $key]);
    }
    return $value;
}

function input_enum(string $key, string $label, array $allowed, bool $required = true): ?string
{
    $value = body_value($key);
    if ($value === null || $value === '') {
        if ($required) {
            fail($label . ' is required.', 422, 'validation_error', ['field' => $key]);
        }
        return null;
    }
    if (!is_string($value) || !in_array($value, $allowed, true)) {
        fail($label . ' must be one of: ' . implode(', ', $allowed) . '.', 422, 'validation_error', ['field' => $key]);
    }
    return $value;
}

function input_bool(string $key, bool $default = false): bool
{
    $value = body_value($key);
    if ($value === null) {
        return $default;
    }
    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

function query_str(string $key, int $max = 100, string $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    if (!is_string($v)) {
        return $default;
    }
    $v = trim($v);
    return mb_substr($v, 0, $max);
}

function query_int(string $key, ?int $default = null, ?int $min = null, ?int $max = null): ?int
{
    if (!isset($_GET[$key]) || $_GET[$key] === '') {
        return $default;
    }
    if (!is_numeric($_GET[$key])) {
        fail('Invalid ' . $key . ' parameter.', 422, 'validation_error', ['field' => $key]);
    }
    $n = (int) $_GET[$key];
    if ($min !== null && $n < $min) {
        $n = $min;
    }
    if ($max !== null && $n > $max) {
        $n = $max;
    }
    return $n;
}

function pagination(): array
{
    $page = query_int('page', 1, 1) ?? 1;
    $per = query_int('per_page', 50, 1, 100) ?? 50;
    $q = query_str('q', 100);
    return ['page' => $page, 'per' => $per, 'offset' => ($page - 1) * $per, 'q' => $q];
}

function paginate_headers(int $total, int $page, int $per): void
{
    header('X-Total-Count: ' . $total);
    header('X-Page: ' . $page);
    header('X-Per-Page: ' . $per);
    header('X-Total-Pages: ' . max(1, (int) ceil($total / max(1, $per))));
}

/** Bind LIMIT/OFFSET safely as integers with native prepares. */
function bind_page(PDOStatement $st, array $params, int $limit, int $offset): void
{
    $i = 1;
    foreach ($params as $p) {
        $st->bindValue($i++, $p);
    }
    $st->bindValue($i++, $limit, PDO::PARAM_INT);
    $st->bindValue($i, $offset, PDO::PARAM_INT);
}

function csv_download(string $filename, array $headers, array $rows): void
{
    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename) . '"');
        header('Cache-Control: no-store');
    }
    $out = fopen('php://output', 'w');
    // Excel BOM so UTF-8 names render correctly.
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers);
    foreach ($rows as $r) {
        fputcsv($out, $r);
    }
    fclose($out);
    exit;
}

function audit(?array $user, string $action, string $entity, $entityId, ?array $old, ?array $new, ?int $branchId = null): void
{
    try {
        q(
            'INSERT INTO audit_log (user_id, branch_id, action, entity, entity_id, old_values, new_values, ip)
             VALUES (?,?,?,?,?,?,?,?)',
            [
                $user['id'] ?? null,
                $branchId,
                $action,
                $entity,
                $entityId === null ? null : (string) $entityId,
                $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
                $new === null ? null : json_encode($new, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
                client_ip(),
            ]
        );
    } catch (Throwable $e) {
        // Audit writes happen after the business transaction commits, so a
        // failure here must not turn a successful sale into an HTTP 500. Log it
        // for the operator; the transactional ledger remains authoritative.
        error_log('Audit log write failed: ' . $e->getMessage());
    }
}

/** Balance of a customer for one branch (or all branches when $branchId null). */
function customer_balance(int $customerId, ?int $branchId = null): array
{
    $b = '';
    $p = [$customerId];
    if ($branchId !== null) {
        $b = ' AND s.branch_id = ?';
        $p[] = $branchId;
    }
    $sale = row(
        "SELECT COALESCE(SUM(CASE WHEN s.payment_type='credit' THEN i.line_total ELSE 0 END),0) charged,
                COALESCE(SUM(i.qty - i.empties_received),0) empties_owed
         FROM sales s JOIN sale_items i ON i.sale_id=s.id
         WHERE s.customer_id=? AND s.is_void=0{$b}",
        $p
    );
    $pb = '';
    $pp = [$customerId];
    if ($branchId !== null) {
        $pb = ' AND p.branch_id = ?';
        $pp[] = $branchId;
    }
    $paid = (float) val("SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.customer_id=? AND p.is_void=0{$pb}", $pp, 0);
    return [
        'charged' => (float) ($sale['charged'] ?? 0),
        'paid' => $paid,
        'balance' => round((float) ($sale['charged'] ?? 0) - $paid, 2),
        'empties_owed' => (int) ($sale['empties_owed'] ?? 0),
    ];
}

function customer_balances_by_branch(int $customerId): array
{
    $rows = rows(
        "SELECT b.id branch_id, b.name branch_name,
            COALESCE((SELECT SUM(CASE WHEN s.payment_type='credit' THEN i.line_total ELSE 0 END)
                      FROM sales s JOIN sale_items i ON i.sale_id=s.id
                      WHERE s.customer_id=? AND s.branch_id=b.id AND s.is_void=0),0) charged,
            COALESCE((SELECT SUM(p.amount) FROM payments p
                      WHERE p.customer_id=? AND p.branch_id=b.id AND p.is_void=0),0) paid,
            COALESCE((SELECT SUM(i.qty-i.empties_received)
                      FROM sales s JOIN sale_items i ON i.sale_id=s.id
                      WHERE s.customer_id=? AND s.branch_id=b.id AND s.is_void=0),0) empties
         FROM branches b
         WHERE b.is_active=1
            OR EXISTS (SELECT 1 FROM sales s2 WHERE s2.customer_id=? AND s2.branch_id=b.id)
         ORDER BY b.name",
        [$customerId, $customerId, $customerId, $customerId]
    );
    foreach ($rows as &$r) {
        $r['branch_id'] = (int) $r['branch_id'];
        $r['charged'] = (float) $r['charged'];
        $r['paid'] = (float) $r['paid'];
        $r['balance'] = round($r['charged'] - $r['paid'], 2);
        $r['empties_owed'] = (int) $r['empties'];
        unset($r['charged'], $r['paid'], $r['empties']);
    }
    return $rows;
}

function stock_balances(int $branchId, array $sizeIds): array
{
    if (!$sizeIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($sizeIds), '?'));
    $params = array_merge([$branchId], array_values($sizeIds));
    $rows = rows(
        "SELECT size_id, COALESCE(SUM(full_change),0) full_qty,
                COALESCE(SUM(empty_change),0) empty_qty,
                COALESCE(SUM(out_change),0) out_qty
         FROM stock_movements
         WHERE branch_id=? AND size_id IN ($in)
         GROUP BY size_id",
        $params
    );
    $out = [];
    foreach ($sizeIds as $id) {
        $out[(int) $id] = ['full_qty' => 0, 'empty_qty' => 0, 'out_qty' => 0];
    }
    foreach ($rows as $r) {
        $out[(int) $r['size_id']] = [
            'full_qty' => (int) $r['full_qty'],
            'empty_qty' => (int) $r['empty_qty'],
            'out_qty' => (int) $r['out_qty'],
        ];
    }
    return $out;
}

/**
 * Lock the branch+size stock rows for the rest of the transaction.
 * MUST be called inside tx(). This is the overselling guard.
 */
function lock_stock(int $branchId, array $sizeIds): void
{
    $sizeIds = array_values(array_unique(array_map('intval', $sizeIds)));
    if (!$sizeIds) {
        return;
    }
    $in = implode(',', array_fill(0, count($sizeIds), '?'));
    // Make sure the lock rows exist (branch creation also seeds them).
    $ins = db()->prepare("INSERT IGNORE INTO stock_locks (branch_id, size_id) SELECT ?, id FROM cylinder_sizes WHERE id IN ($in)");
    $ins->execute(array_merge([$branchId], $sizeIds));
    $st = db()->prepare("SELECT branch_id, size_id FROM stock_locks WHERE branch_id=? AND size_id IN ($in) FOR UPDATE");
    $st->execute(array_merge([$branchId], $sizeIds));
    $st->fetchAll();
}

/** Throw if applying these changes would make any stock bucket negative. */
function assert_stock_after(int $branchId, int $sizeId, int $dFull, int $dEmpty, int $dOut): array
{
    $cur = stock_balances($branchId, [$sizeId])[$sizeId];
    $next = [
        'full_qty' => $cur['full_qty'] + $dFull,
        'empty_qty' => $cur['empty_qty'] + $dEmpty,
        'out_qty' => $cur['out_qty'] + $dOut,
    ];
    foreach ($next as $k => $v) {
        if ($v < 0) {
            $label = $k === 'full_qty' ? 'full cylinders' : ($k === 'empty_qty' ? 'empty cylinders' : 'cylinders with customers');
            fail('This would make ' . $label . ' negative in this branch.', 409, 'negative_stock');
        }
    }
    return $next;
}

function movement(int $branchId, string $date, int $sizeId, string $refType, int $refId, int $dFull, int $dEmpty, int $dOut, ?int $userId, ?string $note = null): void
{
    q(
        'INSERT INTO stock_movements (branch_id, move_date, size_id, ref_type, ref_id, full_change, empty_change, out_change, created_by, note)
         VALUES (?,?,?,?,?,?,?,?,?,?)',
        [$branchId, $date, $sizeId, $refType, $refId, $dFull, $dEmpty, $dOut, $userId, $note]
    );
}

function resolve_price(int $branchId, int $sizeId): float
{
    $p = row('SELECT price FROM branch_prices WHERE branch_id=? AND size_id=?', [$branchId, $sizeId]);
    if ($p !== null) {
        return (float) $p['price'];
    }
    return (float) val('SELECT selling_price FROM cylinder_sizes WHERE id=? AND is_active=1', [$sizeId], 0);
}

function size_label(int $sizeId): string
{
    return (string) val('SELECT label FROM cylinder_sizes WHERE id=?', [$sizeId], '');
}

function doc_no(string $prefix, string $date, int $id): string
{
    return $prefix . '-' . str_replace('-', '', $date) . '-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
}

function branch_exists(int $id): bool
{
    return (bool) val('SELECT id FROM branches WHERE id=?', [$id]);
}

function branch_row(int $id): ?array
{
    return row('SELECT * FROM branches WHERE id=?', [$id]);
}

function require_active_branch(int $id): array
{
    $b = branch_row($id);
    if (!$b) {
        fail('Branch not found.', 404, 'not_found');
    }
    if ((int) $b['is_active'] !== 1) {
        fail('Branch is inactive. Choose an active branch.', 409, 'branch_inactive');
    }
    return $b;
}

function branch_has_open_work(int $branchId): array
{
    $openOrders = (int) val("SELECT COUNT(*) FROM orders WHERE branch_id=? AND status IN ('pending','approved')", [$branchId], 0);
    $stmt = db()->prepare(
        "SELECT size_id, COALESCE(SUM(full_change),0) full_qty, COALESCE(SUM(empty_change),0) empty_qty,
                COALESCE(SUM(out_change),0) out_qty
         FROM stock_movements WHERE branch_id=? GROUP BY size_id
         HAVING full_qty<>0 OR empty_qty<>0 OR out_qty<>0"
    );
    $stmt->execute([$branchId]);
    $stock = $stmt->fetchAll();
    $openTransfers = (int) val(
        "SELECT COUNT(*) FROM stock_transfers
         WHERE (from_branch_id=? OR to_branch_id=?) AND status IN ('requested','approved','in_transit')",
        [$branchId, $branchId],
        0
    );
    return ['open_orders' => $openOrders, 'stock' => $stock, 'open_transfers' => $openTransfers];
}

function to_int($v): int
{
    return (int) round((float) $v);
}

function money($v): float
{
    return round((float) $v, 2);
}
