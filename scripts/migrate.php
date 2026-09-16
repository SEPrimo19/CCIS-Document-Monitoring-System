<?php

declare(strict_types=1);

/**
 * Rebuilds the CCIS-DMS schema, seeds reference data, and creates a default admin.
 *
 * DESTRUCTIVE: drops and recreates all tables, wiping every submission,
 * uploaded-file record, notification, and audit-log entry.
 *
 * Usage:  php scripts/migrate.php [--force]
 *
 * Refuses to run outside development unless --force is passed, so that running
 * it on a live installation is always a deliberate act. Note APP_ENV defaults
 * to production when unset (see config/config.php) — the safe direction.
 */

require dirname(__DIR__) . '/config/env.php';
$config = require dirname(__DIR__) . '/config/config.php';
$db  = $config['db'];
$dir = dirname(__DIR__) . '/database';

$forced = in_array('--force', $argv ?? [], true);
if ($config['app']['env'] !== 'development' && !$forced) {
    fwrite(STDERR, "REFUSED: migrate.php drops and recreates every table, and APP_ENV is '{$config['app']['env']}'.\n");
    fwrite(STDERR, "Database: {$db['name']} on {$db['host']}:{$db['port']} as {$db['user']}.\n");
    fwrite(STDERR, "For local development set APP_ENV=development in .env.\n");
    fwrite(STDERR, "To wipe this database anyway, re-run with --force.\n");
    exit(1);
}

if ($forced && $config['app']['env'] !== 'development') {
    fwrite(STDERR, "WARNING: --force given outside development — wiping {$db['name']} on {$db['host']}.\n");
}

/**
 * Execute a .sql file one statement at a time (avoids PDO multi-statement quirks).
 */
function run_sql_file(PDO $pdo, string $path): void
{
    $sql = (string) file_get_contents($path);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql); // strip line comments
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

try {
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $db['host'], $db['port'], $db['name'], $db['charset']);
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    run_sql_file($pdo, $dir . '/schema.sql');
    echo "schema: tables created\n";

    run_sql_file($pdo, $dir . '/seed.sql');
    echo "seed:   reference data inserted (roles, programs, document types, academic period)\n";

    // Default Secretary account (password hashed here, never stored in SQL).
    // Password comes from SECRETARY_PASSWORD (env/config); defaults to
    // Secretary@123 for local dev — see README for how to override it.
    $adminEmail = 'secretary@nwssu.edu.ph';
    $adminPass  = getenv('SECRETARY_PASSWORD') ?: 'Secretary@123';
    $roleId = (int) $pdo->query("SELECT role_id FROM roles WHERE role_name = 'Secretary'")->fetchColumn();

    // program_id replaces the old free-text program_dept column (FR-36); the
    // codes are seeded by database/seed.sql. The Secretary belongs to the
    // office rather than to a program, so their program_id stays NULL.
    $programIds = $pdo->query('SELECT code, program_id FROM programs')->fetchAll(PDO::FETCH_KEY_PAIR);

    $stmt = $pdo->prepare(
        'INSERT INTO users (role_id, employee_no, first_name, last_name, email, password_hash, program_id, status)
         VALUES (:role, :emp, :fn, :ln, :em, :ph, :program, \'active\')'
    );
    $stmt->execute([
        ':role'    => $roleId,
        ':emp'     => 'SEC-001',
        ':fn'      => 'College',
        ':ln'      => 'Secretary',
        ':em'      => $adminEmail,
        ':ph'      => password_hash($adminPass, PASSWORD_BCRYPT),
        ':program' => null,
    ]);
    echo "secretary: created ({$adminEmail}) — password set from SECRETARY_PASSWORD env var (dev default: Secretary@123); change after first login\n";

    // Dev Faculty accounts, so eagerly-generated Pending submissions (FR-28)
    // have real accounts to land against.
    $facultyRoleId = (int) $pdo->query("SELECT role_id FROM roles WHERE role_name = 'Faculty'")->fetchColumn();
    $facultyPass = getenv('FACULTY_PASSWORD') ?: 'Faculty@123';
    $facultyHash = password_hash($facultyPass, PASSWORD_BCRYPT);
    $facultyAccounts = [
        ['FAC-001', 'Juan', 'Dela Cruz', 'faculty1@nwssu.edu.ph', 'BSIT'],
        ['FAC-002', 'Angelica', 'Reyes',  'faculty2@nwssu.edu.ph', 'BSIS'],
        ['FAC-003', 'Ramon', 'Villanueva', 'faculty3@nwssu.edu.ph', 'BSIT'],
    ];
    foreach ($facultyAccounts as [$emp, $fn, $ln, $em, $programCode]) {
        $stmt->execute([
            ':role'    => $facultyRoleId,
            ':emp'     => $emp,
            ':fn'      => $fn,
            ':ln'      => $ln,
            ':em'      => $em,
            ':ph'      => $facultyHash,
            ':program' => isset($programIds[$programCode]) ? (int) $programIds[$programCode] : null,
        ]);
    }
    echo "faculty: created (faculty1@nwssu.edu.ph, faculty2@nwssu.edu.ph, faculty3@nwssu.edu.ph) — password set from FACULTY_PASSWORD env var (dev default: Faculty@123)\n";

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    echo "\nDONE. " . count($tables) . " tables: " . implode(', ', $tables) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Migrate FAILED: {$e->getMessage()}\n");
    fwrite(STDERR, "Is MySQL running and was the database created (php scripts/db_setup.php)?\n");
    exit(1);
}
