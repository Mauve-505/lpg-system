<?php
declare(strict_types=1);

/**
 * Router for the PHP built-in server used by tests and local preview:
 *
 *   set LPG_CONFIG_FILE=tests\config.test.php
 *   php -S 127.0.0.1:8088 -t . tests/router.php
 *
 * It maps /api/* to the real front controller and serves the built SPA for
 * every other path, matching the Apache .htaccess behaviour.
 */

$root = dirname(__DIR__);
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($uri === '/api' || strpos($uri, '/api/') === 0) {
    $_SERVER['SCRIPT_NAME'] = '/api/index.php';
    require $root . '/api/index.php';
    return true;
}

$candidate = realpath($root . $uri);
if ($uri !== '/' && $candidate !== false && strpos($candidate, $root) === 0 && is_file($candidate)) {
    return false; // let the built-in server stream the static file
}

$index = $root . '/index.html';
if (is_file($index)) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($index);
    return true;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "Frontend not built yet. Run: cd frontend && npm run build\n";
return true;
