<?php

declare(strict_types=1);

/**
 * Application configuration.
 * Values come from environment variables (loaded from .env by the front controller),
 * falling back to stock XAMPP defaults for local development.
 */
// Insecure-by-default configuration is treated as an opt-IN, not an opt-out:
// an unset/unrecognized APP_ENV/APP_DEBUG must land on the hardened production
// posture, never on development (which leaks raw exception text).
$appEnvEnv = getenv('APP_ENV');
$appEnv = $appEnvEnv !== false && strtolower(trim($appEnvEnv)) === 'development'
    ? 'development'
    : 'production';

$appDebugEnv = getenv('APP_DEBUG');
$appDebug = $appDebugEnv === false
    ? ($appEnv === 'development')
    : !in_array(strtolower((string) $appDebugEnv), ['0', 'false', 'off', 'no'], true);

// One configured timezone drives BOTH PHP (public/index.php calls
// date_default_timezone_set() with this) and MySQL (Database::connection()
// sets the session time_zone to the offset below) — see docs/SECURITY.md.
// Asia/Manila (UTC+8) is the institution's zone (NwSSU, Calbayog City).
$appTimezone = getenv('APP_TIMEZONE') ?: 'Asia/Manila';

return [
    'app' => [
        'name'     => getenv('APP_NAME') ?: 'CCIS Document Monitoring System',
        'env'      => $appEnv,
        // Treated as production when APP_ENV=production (or unset/unrecognized)
        // or APP_DEBUG is explicitly falsy.
        'debug'    => $appDebug,
        'timezone' => $appTimezone,
    ],
    'db' => [
        'host'            => getenv('DB_HOST') ?: '127.0.0.1',
        'port'            => getenv('DB_PORT') ?: '3306',
        'name'            => getenv('DB_NAME') ?: 'ccis_dms',
        'user'            => getenv('DB_USER') ?: 'root',
        'pass'            => getenv('DB_PASS') !== false ? getenv('DB_PASS') : '',
        'charset'         => 'utf8mb4',
        // The configured app timezone's current UTC offset (e.g. "+08:00"),
        // never user input — used to keep the MySQL session clock in sync
        // with PHP's so date/time comparisons never straddle a clock skew.
        'timezone_offset' => (new DateTime('now', new DateTimeZone($appTimezone)))->format('P'),
    ],
];
