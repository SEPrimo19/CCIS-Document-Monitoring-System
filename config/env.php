<?php

declare(strict_types=1);

/**
 * Loads the project's .env into the process environment.
 *
 * Shared by the front controller AND the CLI scripts in scripts/. Before this
 * existed only public/index.php parsed .env, so `php scripts/migrate.php` ran
 * with an empty environment: it silently fell back to the stock XAMPP DB
 * credentials no matter what .env said, and every environment check inside it
 * saw an unset APP_ENV.
 *
 * Real environment variables always win over the file — a value already set by
 * the shell, Apache's SetEnv, or a systemd unit is never overwritten here.
 */

$envFile = dirname(__DIR__) . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\"'");
        if (getenv($key) === false) {
            putenv("{$key}={$value}");
        }
    }
}
