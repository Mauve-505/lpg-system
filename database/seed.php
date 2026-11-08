<?php
declare(strict_types=1);

/**
 * Bootstrap data: creates the first owner account when none exists and makes
 * sure every branch has a stock lock row.
 *
 *   php database/seed.php
 *   php database/seed.php --demo     (also adds sample customers)
 *
 * The seeded owner is forced to change the bootstrap password on first login.
 * Never run this with the example password on a public server.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require dirname(__DIR__) . '/api/src/db.php';

$demo = in_array('--demo', $argv, true);

try {
    $pdo = db();
} catch (Throwable $e) {
    fwrite(STDERR, "Cannot connect to the database: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Import database/schema.sql first (or run database/migrate.php).\n");
    exit(1);
}

function table_exists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $st->execute([$table]);
    return (int) $st->fetchColumn() > 0;
}

if (!table_exists($pdo, 'users') || !table_exists($pdo, 'branches')) {
    fwrite(STDERR, "The schema is not installed. Import database/schema.sql first.\n");
    exit(1);
}

$mainBranch = $pdo->query("SELECT id FROM branches WHERE code='MAIN' LIMIT 1")->fetchColumn();
if ($mainBranch === false) {
    $pdo->prepare("INSERT INTO branches (name, code, service_area_notes, is_active) VALUES ('Main Branch','MAIN','Set the real service area in Branches.',1)")
        ->execute();
    $mainBranch = (int) $pdo->lastInsertId();
    echo "Created branch: Main Branch (MAIN)\n";
} else {
    $mainBranch = (int) $mainBranch;
}

$pdo->prepare('INSERT IGNORE INTO stock_locks (branch_id, size_id) SELECT ?, id FROM cylinder_sizes')
    ->execute([$mainBranch]);

$bootstrap = config('bootstrap', []);
$username = (string) ($bootstrap['admin_username'] ?? 'admin');
$password = (string) ($bootstrap['admin_password'] ?? 'admin123');

$ownerCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='owner'")->fetchColumn();
if ($ownerCount === 0) {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $st = $pdo->prepare("INSERT INTO users (name, username, password_hash, role, must_change_password, is_active)
                         VALUES ('Owner', ?, ?, 'owner', 1, 1)");
    $st->execute([$username, $hash]);
    echo "Created owner account: {$username}\n";
    echo "Temporary password: {$password}\n";
    echo "The account must change this password on first login.\n";
} else {
    echo "An owner account already exists; no user was created.\n";
}

if ($demo && table_exists($pdo, 'customers')) {
    $samples = [
        ['Rosa Dela Cruz', '09170000001', 'Purok 1, Main Branch area'],
        ['Jun Santos', '09170000002', 'Purok 2, Main Branch area'],
        ['Ben Reyes', '09170000003', 'Purok 3, Main Branch area'],
    ];
    foreach ($samples as [$name, $contact, $address]) {
        $st = $pdo->prepare('SELECT id FROM customers WHERE contact=?');
        $st->execute([$contact]);
        if ($st->fetchColumn() === false) {
            $pdo->prepare('INSERT INTO customers (name, contact, address) VALUES (?,?,?)')
                ->execute([$name, $contact, $address]);
            echo "Added demo customer: {$name}\n";
        }
    }
    $demoPassword = 'customer123';
    foreach ($samples as $i => [$name, $contact, $address]) {
        $user = 'demo' . ($i + 1);
        $st = $pdo->prepare('SELECT id FROM users WHERE username=?');
        $st->execute([$user]);
        if ($st->fetchColumn() !== false) {
            continue;
        }
        $cid = $pdo->prepare('SELECT id FROM customers WHERE contact=?');
        $cid->execute([$contact]);
        $customerId = $cid->fetchColumn();
        if ($customerId === false) {
            continue;
        }
        $pdo->prepare("INSERT INTO users (name, username, password_hash, role, customer_id, phone)
                       VALUES (?,?,?,'customer',?,?)")
            ->execute([$name, $user, password_hash($demoPassword, PASSWORD_DEFAULT), (int) $customerId, $contact]);
        echo "Added demo login: {$user} / {$demoPassword}\n";
    }
}

echo "Seed complete. Branch id: {$mainBranch}\n";
