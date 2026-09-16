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

if (!function_exists('brand_logo')) {
    /**
     * URL of the college/department logo, or null when none has been supplied.
     *
     * The logo is a supplied asset, not a committed one, so every screen has to
     * render correctly both before and after it arrives: callers fall back to
     * the "CCIS-DMS" wordmark while this returns null. Several extensions are
     * accepted so whichever format the college hands over drops in without a
     * code change — SVG first, since it stays sharp at any size.
     *
     * The lookup is cached for the request: the sidebar and the top bar both
     * call this on every page, and one stat() is enough for both.
     */
    function brand_logo(): ?string
    {
        static $resolved = false;
        static $url = null;

        if ($resolved) {
            return $url;
        }
        $resolved = true;

        foreach (['logo.svg', 'logo.png', 'logo.webp', 'logo.jpg', 'logo.jpeg'] as $candidate) {
            if (is_file(BASE_PATH . '/public/assets/img/' . $candidate)) {
                $url = asset('img/' . $candidate);
                break;
            }
        }

        return $url;
    }
}

if (!function_exists('user_initials')) {
    /**
     * One or two initials for the sidebar avatar, e.g. "JD" for Juan Dela Cruz.
     *
     * This is the fallback the avatar shows until a profile image exists, so it
     * has to produce something for every name the users table can hold: a
     * single-word name yields one letter, and a name that is entirely
     * punctuation or whitespace yields "?" rather than an empty circle.
     * mb_* throughout — first_name/last_name are utf8mb4 and a multi-byte
     * first character must not be sliced in half.
     */
    function user_initials(string $firstName, string $lastName): string
    {
        $letters = '';

        foreach ([$firstName, $lastName] as $part) {
            $part = trim($part);
            if ($part !== '') {
                $letters .= mb_substr($part, 0, 1);
            }
        }

        return $letters !== '' ? mb_strtoupper($letters) : '?';
    }
}

if (!function_exists('avatar_url')) {
    /**
     * URL of a user's profile photo (FR-40), or null when they have none.
     *
     * Takes the stored filename the caller already has — the session user
     * carries `avatar_path`, refreshed by Guard on every request — so rendering
     * the sidebar avatar costs no extra query. The filename itself is never put
     * in the URL: the route is keyed by user id and AvatarController resolves
     * the file, so a stored name cannot leak or be guessed at.
     */
    function avatar_url(?string $storedName, int $userId): ?string
    {
        if ($storedName === null || $storedName === '' || $userId <= 0) {
            return null;
        }

        return url('/avatars/' . $userId);
    }
}

if (!function_exists('brand_mark_class')) {
    /**
     * Body class naming the supplied logo's format, or '' when none exists.
     *
     * The page watermark is a CSS background-image, and the CSP forbids inline
     * style, so the stylesheet cannot be handed a filename at runtime. Instead
     * PHP states which format is present and the stylesheet carries one rule per
     * supported extension. Without this the CSS would have to hard-code
     * logo.png and would silently stop painting the day someone supplies
     * logo.svg instead — the failure being an invisible background rather than
     * an error, which is the kind that survives a long time.
     */
    function brand_mark_class(): string
    {
        $url = brand_logo();

        if ($url === null) {
            return '';
        }

        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));

        return in_array($ext, ['svg', 'png', 'webp', 'jpg', 'jpeg'], true)
            ? 'has-brand-mark brand-mark-' . $ext
            : '';
    }
}

