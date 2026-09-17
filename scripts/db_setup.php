<?php

declare(strict_types=1);

require dirname(__DIR__) . '/config/env.php';
$config = require dirname(__DIR__) . '/config/config.php';
$db = $config['db'];

try {
    $dsn = sprintf('mysql:host=%s;port=%s;charset=%s', $db['host'], $db['port'], $db['charset']);
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $pdo->exec(sprintf(
        'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        $db['name']
    ));

    echo "OK: database '{$db['name']}' is ready on {$db['host']}:{$db['port']}.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "DB setup FAILED: {$e->getMessage()}\n");
    fwrite(STDERR, "Is MySQL running in XAMPP? Check credentials in .env / config.\n");
    exit(1);
}
