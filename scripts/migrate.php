<?php

declare(strict_types=1);

/**
 * Rebuilds the CCIS-DMS schema, seeds reference data, and creates a default admin.
 *
 * DESTRUCTIVE (development): drops and recreates all tables.
 * Usage:  php scripts/migrate.php
 */

$config = require dirname(__DIR__) . '/config/config.php';
$db  = $config['db'];
$dir = dirname(__DIR__) . '/database';

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
    echo "seed:   reference data inserted (roles, document types, academic period)\n";

    // Default administrator (password hashed here, never stored in SQL).
    // Password comes from ADMIN_PASSWORD (env/config); defaults to Admin@123
    // for local dev — see README for how to override it.
    $adminEmail = 'admin@nwssu.edu.ph';
    $adminPass  = getenv('ADMIN_PASSWORD') ?: 'Admin@123';
    $roleId = (int) $pdo->query("SELECT role_id FROM roles WHERE role_name = 'Administrator'")->fetchColumn();

    $stmt = $pdo->prepare(
        'INSERT INTO users (role_id, employee_no, first_name, last_name, email, password_hash, program_dept, status)
         VALUES (:role, :emp, :fn, :ln, :em, :ph, :dept, \'active\')'
    );
    $stmt->execute([
        ':role' => $roleId,
        ':emp'  => 'ADMIN-001',
        ':fn'   => 'System',
        ':ln'   => 'Administrator',
        ':em'   => $adminEmail,
        ':ph'   => password_hash($adminPass, PASSWORD_BCRYPT),
        ':dept' => 'CCIS',
    ]);
    echo "admin:  created ({$adminEmail}) — password set from ADMIN_PASSWORD env var (dev default: Admin@123); change after first login\n";

    // Dev Reviewer/Approver account (Phase 4a.2), so the review workflow has
    // someone to sign in as without hand-inserting a row.
    $reviewerEmail = 'reviewer@nwssu.edu.ph';
    $reviewerPass  = getenv('REVIEWER_PASSWORD') ?: 'Reviewer@123';
    $reviewerRoleId = (int) $pdo->query("SELECT role_id FROM roles WHERE role_name = 'Reviewer/Approver'")->fetchColumn();

    $stmt->execute([
        ':role' => $reviewerRoleId,
        ':emp'  => 'REV-001',
        ':fn'   => 'Marites',
        ':ln'   => 'Bautista',
        ':em'   => $reviewerEmail,
        ':ph'   => password_hash($reviewerPass, PASSWORD_BCRYPT),
        ':dept' => 'CCIS',
    ]);
    echo "reviewer: created ({$reviewerEmail}) — password set from REVIEWER_PASSWORD env var (dev default: Reviewer@123)\n";

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
    foreach ($facultyAccounts as [$emp, $fn, $ln, $em, $dept]) {
        $stmt->execute([
            ':role' => $facultyRoleId,
            ':emp'  => $emp,
            ':fn'   => $fn,
            ':ln'   => $ln,
            ':em'   => $em,
            ':ph'   => $facultyHash,
            ':dept' => $dept,
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
