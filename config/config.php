<?php

declare(strict_types=1);

/**
 * Application configuration.
 * Values come from environment variables (loaded from .env by the front controller),
 * falling back to stock XAMPP defaults for local development.
 */
return [
    'app' => [
        'name' => getenv('APP_NAME') ?: 'CCIS Document Monitoring System',
        'env'  => getenv('APP_ENV') ?: 'development',
    ],
    'db' => [
        'host'    => getenv('DB_HOST') ?: '127.0.0.1',
        'port'    => getenv('DB_PORT') ?: '3306',
        'name'    => getenv('DB_NAME') ?: 'ccis_dms',
        'user'    => getenv('DB_USER') ?: 'root',
        'pass'    => getenv('DB_PASS') !== false ? getenv('DB_PASS') : '',
        'charset' => 'utf8mb4',
    ],
];
