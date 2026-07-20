<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\LoginAttempt;

/**
 * Brute-force login throttle (Phase 3 remediation, Bucket B). Keyed on
 * email+IP so a single bad actor can't lock out a legitimate user from a
 * different network, while still bounding guesses against one account from
 * one source.
 */
final class LoginThrottle
{
    /** Failed attempts allowed within the window before locking out. */
    private const MAX_ATTEMPTS = 5;

    /**
     * Both the counting window and, effectively, the lockout duration: once
     * MAX_ATTEMPTS failures land inside this many minutes, further tries are
     * blocked until the oldest of them ages out of the window.
     */
    private const WINDOW_MINUTES = 15;

    public static function isLockedOut(string $email, string $ip): bool
    {
        return LoginAttempt::recentFailureCount($email, $ip, self::WINDOW_MINUTES) >= self::MAX_ATTEMPTS;
    }
}
