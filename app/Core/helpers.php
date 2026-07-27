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

if (!function_exists('period_label')) {
    /**
     * One canonical human label for an academic period, so every screen names
     * it the same way (previously each view hand-rolled its own format — some
     * "AY 2026-2027, 1st Semester", some "2026-2027 — 1st Semester"). Uses the
     * admin-set label when present, falling back to the school year + semester.
     *
     * `?:` not `??`: an empty-string label is as good as no label and must fall
     * through to the computed name.
     *
     * @param array{school_year:string,semester:string,label?:?string} $period
     */
    function period_label(array $period): string
    {
        $fallback = 'AY ' . $period['school_year'] . ', ' . $period['semester'] . ' Semester';

        return ($period['label'] ?? '') !== '' ? (string) $period['label'] : $fallback;
    }
}
