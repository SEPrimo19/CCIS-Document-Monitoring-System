<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Session-bound CSRF token generation & verification for state-changing forms
 * (e.g., the login POST).
 */
final class Csrf
{
    private const SESSION_KEY = 'csrf_token';

    /**
     * The current token for this session, generating one on first use.
     */
    public static function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    /**
     * Constant-time comparison of a submitted token against the session's token.
     */
    public static function verify(?string $token): bool
    {
        $expected = $_SESSION[self::SESSION_KEY] ?? null;

        return is_string($expected) && is_string($token) && $token !== '' && hash_equals($expected, $token);
    }
}
