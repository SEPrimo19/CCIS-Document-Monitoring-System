<?php

declare(strict_types=1);

/**
 * Global URL helpers. BASE_URL is defined once by the front controller from
 * the app's own script location (empty at the web root, or e.g.
 * "/ccis-dms/public" when served from a subfolder), so every outbound link,
 * redirect, and asset reference works under either deployment. Loaded via an
 * explicit require in public/index.php — the autoloader only loads classes.
 */

if (!function_exists('url')) {
    function url(string $path = '/'): string
    {
        return BASE_URL . '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        return BASE_URL . '/assets/' . ltrim($path, '/');
    }
}
