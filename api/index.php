<?php
declare(strict_types=1);

/**
 * Single API front controller / router.
 * Apache rewrites /api/* here (see api/.htaccess).
 */

require __DIR__ . '/src/db.php';
require __DIR__ . '/src/helpers.php';
require __DIR__ . '/src/auth.php';

date_default_timezone_set((string) config('timezone', 'Asia/Manila'));

// Production: never display errors, always log them.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_reporting', (string) (E_ALL & ~E_DEPRECATED));
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0750, true);
}
if (is_dir($logDir) && is_writable($logDir)) {
    ini_set('error_log', $logDir . '/error.log');
}

// Security headers. CSP allows the Google Fonts stylesheet used by the site
// and inline styles required by framer-motion, nothing else.
$csp = "default-src 'self'; base-uri 'self'; object-src 'none'; "
    . "script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
    . "font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; "
    . "connect-src 'self'; frame-ancestors 'none'; form-action 'self'";
header('Content-Security-Policy: ' . $csp);
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
header('Cross-Origin-Resource-Policy: same-origin');
if (is_https()) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// Resolve the route relative to the /api mount, wherever the app is installed.
$uriPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/api/index.php');
$baseUrl = substr($script, 0, (int) strrpos($script, '/'));
$path = $uriPath;
if ($baseUrl !== '' && strpos($uriPath, $baseUrl) === 0) {
    $path = substr($uriPath, strlen($baseUrl));
} elseif (($pos = strpos($uriPath, '/api/')) !== false) {
    $path = substr($uriPath, $pos + 4);
}
$path = trim(rawurldecode($path), '/');
$GLOBALS['LPG_PATH'] = $path;
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

start_session();

if ($method === 'OPTIONS') {
    http_response_code(204);
    header('Allow: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    exit;
}

$routes = [
    ['GET', '#^health$#', 'api_health'],
    ['GET', '#^csrf$#', 'api_csrf'],
    ['POST', '#^login$#', 'api_login'],
    ['POST', '#^logout$#', 'api_logout'],
    ['POST', '#^register$#', 'api_register'],
    ['POST', '#^auth/request-otp$#', 'api_request_otp'],

    ['GET', '#^branches/public$#', 'api_branches_public'],
    ['GET', '#^sizes$#', 'api_sizes_list'],

    ['GET', '#^me$#', 'api_me'],
    ['POST', '#^me/password$#', 'api_change_password'],

    ['GET', '#^my/account$#', 'api_my_account'],
    ['GET', '#^my/orders$#', 'api_my_orders_list'],
    ['POST', '#^my/orders$#', 'api_my_orders_create'],
    ['GET', '#^my/orders/(\d+)$#', 'api_my_order_show'],
    ['PATCH', '#^my/orders/(\d+)$#', 'api_my_order_update'],
    ['PUT', '#^my/orders/(\d+)$#', 'api_my_order_update'],
    ['POST', '#^my/orders/(\d+)/cancel$#', 'api_my_order_cancel'],
    ['GET', '#^my/payments$#', 'api_my_payments'],

    ['GET', '#^dashboard$#', 'api_dashboard'],

    ['GET', '#^customers$#', 'api_customers_list'],
    ['POST', '#^customers$#', 'api_customers_create'],
    ['PUT', '#^customers/(\d+)$#', 'api_customers_update'],
    ['DELETE', '#^customers/(\d+)$#', 'api_customers_delete'],

    ['GET', '#^payments$#', 'api_payments_list'],
    ['POST', '#^payments$#', 'api_payments_create'],
    ['DELETE', '#^payments/(\d+)$#', 'api_payments_void'],

    ['GET', '#^deliveries$#', 'api_deliveries_list'],
    ['POST', '#^deliveries$#', 'api_deliveries_create'],
    ['DELETE', '#^deliveries/(\d+)$#', 'api_deliveries_void'],

    ['GET', '#^sales$#', 'api_sales_list'],
    ['POST', '#^sales$#', 'api_sales_create'],
    ['GET', '#^sales/(\d+)$#', 'api_sales_show'],
    ['GET', '#^sales/(\d+)/receipt$#', 'api_sales_receipt'],
    ['DELETE', '#^sales/(\d+)$#', 'api_sales_void'],

    ['GET', '#^orders$#', 'api_orders_list'],
    ['GET', '#^orders/(\d+)$#', 'api_order_show'],
    ['POST', '#^orders/(\d+)/approve$#', 'api_order_approve'],
    ['POST', '#^orders/(\d+)/cancel$#', 'api_order_cancel'],
    ['POST', '#^orders/(\d+)/deliver$#', 'api_order_deliver'],
    ['POST', '#^orders/(\d+)/assign$#', 'api_order_assign'],

    ['GET', '#^adjustments$#', 'api_adjustments_list'],
    ['POST', '#^adjustments$#', 'api_adjustments_create'],
    ['DELETE', '#^adjustments/(\d+)$#', 'api_adjustments_void'],

    ['GET', '#^sizes/all$#', 'api_sizes_all'],
    ['POST', '#^sizes$#', 'api_sizes_create'],
    ['DELETE', '#^sizes/(\d+)$#', 'api_sizes_delete'],

    ['GET', '#^users$#', 'api_users_list'],
    ['POST', '#^users$#', 'api_users_create'],
    ['PUT', '#^users/(\d+)$#', 'api_users_update'],
    ['DELETE', '#^users/(\d+)$#', 'api_users_delete'],
    ['POST', '#^users/(\d+)/reset-password$#', 'api_users_reset_password'],

    ['GET', '#^branches$#', 'api_branches_list'],
    ['POST', '#^branches$#', 'api_branches_create'],
    ['PUT', '#^branches/(\d+)$#', 'api_branches_update'],
    ['POST', '#^branches/(\d+)/deactivate$#', 'api_branches_deactivate'],
    ['POST', '#^branches/(\d+)/activate$#', 'api_branches_activate'],
    ['GET', '#^branches/(\d+)/prices$#', 'api_branch_prices_get'],
    ['PUT', '#^branches/(\d+)/prices$#', 'api_branch_prices_put'],

    ['GET', '#^transfers$#', 'api_transfers_list'],
    ['POST', '#^transfers$#', 'api_transfers_create'],
    ['GET', '#^transfers/(\d+)$#', 'api_transfer_show'],
    ['POST', '#^transfers/(\d+)/approve$#', 'api_transfer_approve'],
    ['POST', '#^transfers/(\d+)/ship$#', 'api_transfer_ship'],
    ['POST', '#^transfers/(\d+)/receive$#', 'api_transfer_receive'],
    ['POST', '#^transfers/(\d+)/cancel$#', 'api_transfer_cancel'],

    ['GET', '#^reports/daily$#', 'api_report_daily'],
    ['GET', '#^reports/sales$#', 'api_report_sales'],
    ['GET', '#^reports/unpaid$#', 'api_report_unpaid'],
    ['GET', '#^reports/empties$#', 'api_report_empties'],
    ['GET', '#^reports/stock$#', 'api_report_stock'],
    ['GET', '#^reports/branch-comparison$#', 'api_report_branch_comparison'],
    ['GET', '#^audit$#', 'api_audit_list'],
];

// Load handlers only after routing setup.
foreach (glob(__DIR__ . '/src/handlers/*.php') ?: [] as $file) {
    require_once $file;
}

try {
    // CSRF applies to every state-changing request, including login and signup.
    if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        require_csrf();
    }

    // JSON content type is required for JSON bodies.
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        $ct = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if ($ct !== '' && strpos($ct, 'application/json') === false) {
            fail('Content-Type must be application/json.', 415, 'unsupported_media_type');
        }
    }

    foreach ($routes as $r) {
        if ($r[0] === $method && preg_match($r[1], $path, $m)) {
            $params = array_map('intval', array_slice($m, 1));
            if (!function_exists($r[2])) {
                throw new RuntimeException('Route handler missing: ' . $r[2]);
            }
            $r[2]($params);
            // Handlers always send a response; reaching here is a bug.
            fail('No response produced.', 500, 'server_error');
        }
    }
    fail('Not found.', 404, 'not_found');
} catch (ApiException $e) {
    json_response(array_merge([
        'error' => $e->getMessage(),
        'code' => $e->errorCode,
    ], $e->extra), $e->status);
} catch (PDOException $e) {
    error_log('Database error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_response(['error' => 'A database error occurred.', 'code' => 'server_error'], 500);
} catch (Throwable $e) {
    error_log('Unhandled error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $body = ['error' => 'Server error.', 'code' => 'server_error'];
    if (config('debug', false)) {
        $body['detail'] = $e->getMessage();
    }
    json_response($body, 500);
}
