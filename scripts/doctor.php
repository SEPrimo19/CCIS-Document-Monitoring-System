<?php

declare(strict_types=1);

/**
 * Checks a fresh install and says exactly what is wrong.
 *
 * Written because "it errors when I run it" is the hardest report to act on.
 * Every check below is one that has actually stopped someone: the wrong
 * directory, a missing .env, a commented-out APP_ENV, MySQL not started, a
 * database that does not exist yet, an extension XAMPP ships disabled.
 *
 * Read-only. It creates nothing, changes nothing, and is safe to run at any
 * time, including against an installation with real data.
 *
 *   C:\xampp\php\php.exe scripts/doctor.php
 */

$root = dirname(__DIR__);
$fail = 0;
$warn = 0;

function say(string $status, string $label, string $detail = ''): void
{
    global $fail, $warn;
    $mark = ['ok' => '  ok  ', 'FAIL' => ' FAIL ', 'warn' => ' warn '][$status];
    echo $mark . ' ' . $label . "\n";
    if ($detail !== '') {
        foreach (explode("\n", $detail) as $line) {
            echo '        ' . $line . "\n";
        }
    }
    if ($status === 'FAIL') { $fail++; }
    if ($status === 'warn') { $warn++; }
}

echo "\nCCIS-DMS setup check\n";
echo str_repeat('-', 62) . "\n";

/* --- 1. are we in the right folder? ------------------------------------- */
// The single most common mistake: the project root is ccis-dms/, but people
// are often handed the parent folder and run commands one level too high.
if (is_file($root . '/public/index.php') && is_dir($root . '/app')) {
    say('ok', 'Running from the project root', $root);
} else {
    say('FAIL', 'Wrong folder',
        "This script is in the right place, but the project around it is not.\n"
        . "The project root is the folder containing public/ and app/, named\n"
        . "ccis-dms. If you were given the PARENT folder, go one level down:\n"
        . "    cd ccis-dms");
}

/* --- 2. PHP itself ------------------------------------------------------- */
if (PHP_VERSION_ID >= 80100) {
    say('ok', 'PHP version', PHP_VERSION . ' at ' . PHP_BINARY);
} else {
    say('FAIL', 'PHP too old',
        'Found ' . PHP_VERSION . ", need 8.1 or newer.\n"
        . 'Use the PHP that ships with XAMPP: C:\\xampp\\php\\php.exe');
}

foreach (['pdo_mysql' => 'database access',
          'fileinfo'  => 'upload type checking',
          'zip'       => 'reading .docx files',
          'mbstring'  => 'text handling'] as $ext => $why) {
    if (extension_loaded($ext)) {
        say('ok', "Extension {$ext}", $why);
    } else {
        say('FAIL', "Extension {$ext} is missing",
            "Needed for {$why}. Enable it in C:\\xampp\\php\\php.ini by removing\n"
            . "the ';' in front of  extension={$ext}  then restart the server.");
    }
}

/* --- 3. the .env file ---------------------------------------------------- */
$envPath = $root . '/.env';
if (!is_file($envPath)) {
    say('FAIL', '.env file is missing',
        "It is deliberately not shipped, because it holds machine settings.\n"
        . "Create it from the example, then set the development flag:\n"
        . "    copy .env.example .env\n"
        . "    echo APP_ENV=development>> .env");
} else {
    say('ok', '.env file exists', $envPath);
    require $root . '/config/env.php';
    $env = getenv('APP_ENV');
    if ($env === 'development') {
        say('ok', 'APP_ENV is development', 'Setup scripts are allowed to run.');
    } else {
        say('FAIL', 'APP_ENV is not set to development',
            'Found: ' . ($env === false ? '(not set)' : $env) . "\n"
            . "Anything other than 'development' resolves to production, and\n"
            . "scripts/migrate.php refuses to run there, because it drops every\n"
            . "table. For a local copy, add the line:\n"
            . "    echo APP_ENV=development>> .env");
    }
}

/* --- 4. the database ----------------------------------------------------- */
$config = require $root . '/config/config.php';
$db = $config['db'];

try {
    $dsn = "mysql:host={$db['host']};port={$db['port']};charset=utf8mb4";
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    say('ok', 'MySQL is reachable', "{$db['host']}:{$db['port']} as {$db['user']}");

    $found = $pdo->query('SHOW DATABASES LIKE ' . $pdo->quote($db['name']))->fetchColumn();
    if ($found === false) {
        say('FAIL', "Database '{$db['name']}' does not exist",
            "Create it:\n    C:\\xampp\\php\\php.exe scripts/db_setup.php");
    } else {
        say('ok', "Database '{$db['name']}' exists");

        $pdo->exec("USE `{$db['name']}`");
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        if (count($tables) === 0) {
            say('FAIL', 'Database has no tables',
                "Create them and load the seed data:\n"
                . "    C:\\xampp\\php\\php.exe scripts/migrate.php");
        } elseif (count($tables) < 13) {
            say('warn', 'Database has ' . count($tables) . ' tables, expected 13',
                "Some migrations may not have run. On a database with data you\n"
                . "want to keep, use the incremental scripts rather than migrate.php:\n"
                . "    scripts/migrate_batch_a.php  and  scripts/migrate_batch_c.php");
        } else {
            $users = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            say('ok', 'Database has ' . count($tables) . ' tables', "{$users} user account(s)");
            if ($users === 0) {
                say('warn', 'No user accounts exist', 'You will not be able to sign in.');
            }

            // Compare the live tables against database/schema.sql. A column the
            // schema defines but the database lacks is the failure mode that is
            // hardest to read from the outside: the app starts, sign-in works,
            // and then every signed-in page answers 500 with an "Unknown column"
            // message. It happens when a migration adds a column by ALTER and a
            // database built earlier never receives it.
            $schemaFile = $root . '/database/schema.sql';
            if (is_file($schemaFile)) {
                $sqlText = (string) file_get_contents($schemaFile);
                $drift = [];
                if (preg_match_all('/CREATE TABLE (\w+)\s*\((.*?)\n\)\s*ENGINE/s', $sqlText, $mm, PREG_SET_ORDER)) {
                    foreach ($mm as $block) {
                        [$whole, $tableName, $bodyText] = $block;
                        if (!in_array($tableName, $tables, true)) {
                            $drift[] = "table {$tableName} is missing entirely";
                            continue;
                        }
                        $liveCols = $pdo->query("SHOW COLUMNS FROM `{$tableName}`")->fetchAll(PDO::FETCH_COLUMN);
                        foreach (explode("\n", $bodyText) as $line) {
                            $line = trim($line);
                            if ($line === '' || str_starts_with($line, '--')) {
                                continue;
                            }
                            if (preg_match('/^([a-z_]+)\s+(INT|VARCHAR|TEXT|DATE|DATETIME|TINYINT|ENUM|DECIMAL|BIGINT)/i', $line, $c)
                                && !in_array($c[1], $liveCols, true)) {
                                $drift[] = "{$tableName}.{$c[1]}";
                            }
                        }
                    }
                }
                if ($drift === []) {
                    say('ok', 'Database matches database/schema.sql');
                } else {
                    say('FAIL', 'Database is missing columns the code expects',
                        implode(', ', $drift) . "\n"
                        . "Your database was created before these were added. Bring it up to\n"
                        . "date WITHOUT losing your data:\n"
                        . "    C:\\xampp\\php\\php.exe scripts/migrate_batch_a.php\n"
                        . "    C:\\xampp\\php\\php.exe scripts/migrate_batch_c.php\n"
                        . "Both are ALTER-based and safe to re-run. Only if the data does not\n"
                        . "matter, scripts/migrate.php rebuilds everything from scratch.");
                }
            }
        }
    }
} catch (PDOException $e) {
    say('FAIL', 'Cannot connect to MySQL',
        $e->getMessage() . "\n"
        . "Start MySQL in the XAMPP Control Panel. If it will not start, another\n"
        . "program is probably using port 3306.");
}

/* --- 5. writable storage ------------------------------------------------- */
// Writability is tested by actually writing, not with is_writable(). On
// Windows is_writable() reports a directory's read-only attribute rather than
// its real ACL, and it returns false for folders the application writes to
// without trouble. Trusting it sends people chasing a problem they do not have.
foreach (['storage/uploads' => 'uploaded documents',
          'storage/avatars' => 'profile photos',
          'storage/previews' => 'converted previews'] as $dir => $what) {
    $path = $root . '/' . $dir;
    if (!is_dir($path)) {
        say('warn', "{$dir} does not exist", "Created automatically on first use ({$what}).");
        continue;
    }
    $probe = $path . '/.doctor-write-test-' . bin2hex(random_bytes(4));
    if (@file_put_contents($probe, 'test') !== false) {
        @unlink($probe);
        say('ok', "{$dir} is writable", $what);
    } else {
        say('FAIL', "{$dir} is not writable",
            "Needed for {$what}. Check the folder's permissions, and that it is\n"
            . 'not marked read-only.');
    }
}

/* --- 6. optional --------------------------------------------------------- */
$soffice = ['C:\\Program Files\\LibreOffice\\program\\soffice.exe',
            'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe'];
$hasOffice = false;
foreach ($soffice as $p) { if (is_file($p)) { $hasOffice = true; break; } }
if ($hasOffice) {
    say('ok', 'LibreOffice found', 'Word documents will preview as real pages.');
} else {
    say('warn', 'LibreOffice not found (optional)',
        "Word documents still preview, using a simpler built-in renderer.\n"
        . 'Nothing else is affected.');
}

/* --- verdict ------------------------------------------------------------- */
echo str_repeat('-', 62) . "\n";
if ($fail === 0 && $warn === 0) {
    echo "Everything checks out. Start the server with:\n";
    echo "    C:\\xampp\\php\\php.exe -S localhost:8000 -t public\n\n";
    exit(0);
}
if ($fail === 0) {
    echo "No blocking problems ({$warn} warning" . ($warn === 1 ? '' : 's') . "). Start the server with:\n";
    echo "    C:\\xampp\\php\\php.exe -S localhost:8000 -t public\n\n";
    exit(0);
}
echo "{$fail} problem" . ($fail === 1 ? '' : 's') . " to fix, listed above.\n";
echo "Full instructions are in SETUP.md.\n\n";
exit(1);
