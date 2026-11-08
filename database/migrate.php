<?php
declare(strict_types=1);

/**
 * Safe migration runner.
 *
 *   php database/migrate.php --dry-run
 *   php database/migrate.php
 *   php database/migrate.php --no-backup   (only when you already have a backup)
 *
 * Takes a mysqldump backup before the first pending migration, then applies
 * database/migrations/*.sql in filename order and records each version in
 * schema_migrations. DDL auto-commits in MySQL, so on failure restore the
 * backup rather than re-running a half-applied file.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require dirname(__DIR__) . '/api/src/db.php';

/**
 * Split a migration file into individual statements.
 *
 * The PDO MySQL driver in this environment rejects multi-statement queries even
 * with MYSQL_ATTR_MULTI_STATEMENTS, so migrations are executed one statement at
 * a time. This splitter understands quotes, backticks, `--` / `#` comments and
 * block comments. Migration files must not use DELIMITER or stored programs.
 */
function split_sql_statements(string $sql): array
{
    $out = [];
    $buf = '';
    $len = strlen($sql);
    $single = false;
    $double = false;
    $backtick = false;
    $lineComment = false;
    $blockComment = false;

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';

        if ($lineComment) {
            if ($ch === "\n") {
                $lineComment = false;
                $buf .= $ch;
            }
            continue;
        }
        if ($blockComment) {
            if ($ch === '*' && $next === '/') {
                $blockComment = false;
                $i++;
            }
            continue;
        }

        if (!$single && !$double && !$backtick) {
            if ($ch === '-' && $next === '-' && ($i + 2 >= $len || ctype_space($sql[$i + 2]))) {
                $lineComment = true;
                $i++;
                continue;
            }
            if ($ch === '#') {
                $lineComment = true;
                continue;
            }
            if ($ch === '/' && $next === '*') {
                $blockComment = true;
                $i++;
                continue;
            }
        }

        if ($single) {
            $buf .= $ch;
            if ($ch === '\\' && $next !== '') {
                $buf .= $next;
                $i++;
            } elseif ($ch === "'") {
                if ($next === "'") {
                    $buf .= $next;
                    $i++;
                } else {
                    $single = false;
                }
            }
            continue;
        }
        if ($double) {
            $buf .= $ch;
            if ($ch === '\\' && $next !== '') {
                $buf .= $next;
                $i++;
            } elseif ($ch === '"') {
                if ($next === '"') {
                    $buf .= $next;
                    $i++;
                } else {
                    $double = false;
                }
            }
            continue;
        }
        if ($backtick) {
            $buf .= $ch;
            if ($ch === '`') {
                if ($next === '`') {
                    $buf .= $next;
                    $i++;
                } else {
                    $backtick = false;
                }
            }
            continue;
        }

        if ($ch === "'") {
            $single = true;
            $buf .= $ch;
            continue;
        }
        if ($ch === '"') {
            $double = true;
            $buf .= $ch;
            continue;
        }
        if ($ch === '`') {
            $backtick = true;
            $buf .= $ch;
            continue;
        }
        if ($ch === ';') {
            $statement = trim($buf);
            if ($statement !== '') {
                $out[] = $statement;
            }
            $buf = '';
            continue;
        }
        $buf .= $ch;
    }

    $statement = trim($buf);
    if ($statement !== '') {
        $out[] = $statement;
    }
    return $out;
}

$dryRun = in_array('--dry-run', $argv, true);
$noBackup = in_array('--no-backup', $argv, true);
$db = config('db');

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], (int) $db['port'], $db['name']),
        (string) $db['user'],
        (string) $db['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
        ]
    );
} catch (Throwable $e) {
    fwrite(STDERR, "Cannot connect to the database: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Create/import the database first: mysql -u root -p < database/schema.sql\n");
    exit(1);
}

$pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(50) PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$applied = [];
foreach ($pdo->query('SELECT version FROM schema_migrations')->fetchAll() as $r) {
    $applied[$r['version']] = true;
}

$files = glob(__DIR__ . '/migrations/*.sql') ?: [];
$pending = [];
foreach ($files as $file) {
    $name = basename($file);
    if (preg_match('/_down\.sql$/i', $name)) {
        continue;
    }
    $version = pathinfo($name, PATHINFO_FILENAME);
    if (!isset($applied[$version])) {
        $pending[] = ['version' => $version, 'file' => $file, 'name' => $name];
    }
}

if (!$pending) {
    echo "Database is up to date. Nothing to migrate.\n";
    exit(0);
}

echo "Pending migrations:\n";
foreach ($pending as $m) {
    echo "  - {$m['name']}\n";
}

if ($dryRun) {
    echo "Dry run: no changes made.\n";
    exit(0);
}

if (!$noBackup) {
    $backupDir = __DIR__ . '/backups';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0750, true) && !is_dir($backupDir)) {
        fwrite(STDERR, "Could not create backup directory: {$backupDir}\n");
        exit(1);
    }
    $backupFile = $backupDir . '/' . $db['name'] . '_' . date('Ymd_His') . '_before_migrations.sql';

    $candidates = [(string) config('db.mysqldump_path', '')];
    if (DIRECTORY_SEPARATOR === '\\') {
        $candidates[] = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
    }
    $dump = null;
    foreach ($candidates as $candidate) {
        if ($candidate === '') {
            continue;
        }
        if (is_file($candidate)) {
            $dump = $candidate;
            break;
        }
        $which = @shell_exec('where ' . escapeshellarg($candidate) . ' 2>NUL');
        if (is_string($which) && trim($which) !== '') {
            $dump = $candidate;
            break;
        }
    }

    if ($dump === null) {
        fwrite(STDERR, "mysqldump was not found. Set db.mysqldump_path in api/config.php,\n");
        fwrite(STDERR, "or re-run with --no-backup after taking your own backup.\n");
        exit(1);
    }

    $oldPassword = getenv('MYSQL_PWD');
    putenv('MYSQL_PWD=' . (string) $db['pass']);
    $cmd = escapeshellarg($dump)
        . ' --host=' . escapeshellarg((string) $db['host'])
        . ' --port=' . (int) $db['port']
        . ' --user=' . escapeshellarg((string) $db['user'])
        . ' --single-transaction --routines --skip-lock-tables '
        . escapeshellarg((string) $db['name'])
        . ' > ' . escapeshellarg($backupFile) . ' 2>&1';
    exec($cmd, $output, $status);
    if ($oldPassword === false) {
        putenv('MYSQL_PWD');
    } else {
        putenv('MYSQL_PWD=' . $oldPassword);
    }
    if ($status !== 0 || !is_file($backupFile) || filesize($backupFile) === 0) {
        fwrite(STDERR, "Backup failed. Nothing was migrated.\n" . implode("\n", $output) . "\n");
        exit(1);
    }
    echo "Backup: {$backupFile}\n";
}

foreach ($pending as $m) {
    echo "Applying {$m['name']} ... ";
    $sql = file_get_contents($m['file']);
    if ($sql === false || trim($sql) === '') {
        fwrite(STDERR, "cannot read file\n");
        exit(1);
    }
    try {
        foreach (split_sql_statements($sql) as $statement) {
            // The PDO connection already selects the configured database.
            if (preg_match('/^USE\s+/i', $statement)) {
                continue;
            }
            $pdo->exec($statement);
        }
        $pdo->prepare('INSERT IGNORE INTO schema_migrations (version) VALUES (?)')->execute([$m['version']]);
        echo "ok\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "FAILED\n" . $e->getMessage() . "\n");
        fwrite(STDERR, "Restore the backup above before retrying.\n");
        exit(1);
    }
}

echo "All migrations applied.\n";
