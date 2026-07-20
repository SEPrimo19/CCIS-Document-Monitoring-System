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
    $adminEmail = 'admin@nwssu.edu.ph';
    $adminPass  = 'Admin@123';
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
    echo "admin:  created ({$adminEmail} / {$adminPass}) — change after first login\n";

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    echo "\nDONE. " . count($tables) . " tables: " . implode(', ', $tables) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Migrate FAILED: {$e->getMessage()}\n");
    fwrite(STDERR, "Is MySQL running and was the database created (php scripts/db_setup.php)?\n");
    exit(1);
}
