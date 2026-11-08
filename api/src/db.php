<?php
declare(strict_types=1);

/**
 * Configuration + PDO connection.
 * All queries in the project go through PDO with native (non-emulated)
 * prepared statements. No user input is ever concatenated into SQL.
 */

function config(?string $key = null, $default = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $path = getenv('LPG_CONFIG_FILE');
        if (!$path) {
            $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.php';
        }
        if (!is_file($path)) {
            throw new RuntimeException('API configuration file is missing. Copy api/config.example.php to api/config.php.');
        }
        $cfg = require $path;
        if (!is_array($cfg)) {
            throw new RuntimeException('API configuration must return an array.');
        }
    }
    if ($key === null) {
        return $cfg;
    }
    $value = $cfg;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $c = config('db');
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $c['host'],
        (int) $c['port'],
        $c['name'],
        $c['charset'] ?? 'utf8mb4'
    );

    try {
        $pdo = new PDO($dsn, (string) $c['user'], (string) $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            // Emulated prepares off: real server-side prepares.
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_PERSISTENT => false,
        ]);
        // Business dates are Philippine time. Keep MySQL and PHP aligned.
        $pdo->exec("SET time_zone = '+08:00'");
    } catch (PDOException $e) {
        error_log('DB connection failed: ' . $e->getMessage());
        throw new RuntimeException('Database connection failed.', 0, $e);
    }

    return $pdo;
}

/** Run a query and return the statement. */
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function rows(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function row(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

function val(string $sql, array $params = [], $default = null)
{
    $r = q($sql, $params)->fetchColumn();
    return $r === false ? $default : $r;
}

/**
 * Run a callback inside a transaction and always roll back on error.
 * Stock changes and their ledger rows must be written in the same transaction.
 */
function tx(callable $fn)
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $result = $fn($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
