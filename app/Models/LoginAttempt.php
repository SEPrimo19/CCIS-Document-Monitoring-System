<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use DateInterval;
use DateTimeImmutable;
use PDO;

/**
 * login_attempts table access. A security/throttle log for the brute-force
 * defense in LoginThrottle — deliberately not tied to a real user account,
 * since a failed attempt may name an email that doesn't exist.
 */
final class LoginAttempt
{
    /**
     * Count failed attempts for this email+IP within the last $withinMinutes
     * minutes. The cutoff is computed in PHP and bound as a parameter, never
     * interpolated.
     */
    public static function recentFailureCount(string $email, string $ip, int $withinMinutes): int
    {
        $cutoff = (new DateTimeImmutable())
            ->sub(new DateInterval('PT' . $withinMinutes . 'M'))
            ->format('Y-m-d H:i:s');

        $stmt = self::pdo()->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE email = :email AND ip_address = :ip AND success = 0 AND attempted_at >= :cutoff'
        );
        $stmt->execute([
            ':email'  => $email,
            ':ip'     => $ip,
            ':cutoff' => $cutoff,
        ]);

        return (int) $stmt->fetchColumn();
    }

    public static function record(string $email, string $ip, bool $success): void
    {
        $stmt = self::pdo()->prepare(
            'INSERT INTO login_attempts (email, ip_address, success) VALUES (:email, :ip, :success)'
        );
        $stmt->execute([
            ':email'   => $email,
            ':ip'      => $ip,
            ':success' => $success ? 1 : 0,
        ]);
    }

    /**
     * Drop the failed-attempt history for this email+IP, called after a
     * successful login so earlier typos don't count against the account.
     */
    public static function clearFailures(string $email, string $ip): void
    {
        $stmt = self::pdo()->prepare(
            'DELETE FROM login_attempts WHERE email = :email AND ip_address = :ip AND success = 0'
        );
        $stmt->execute([
            ':email' => $email,
            ':ip'    => $ip,
        ]);
    }

    private static function pdo(): PDO
    {
        static $config = null;
        if ($config === null) {
            $config = require dirname(__DIR__, 2) . '/config/config.php';
        }

        return Database::connection($config['db']);
    }
}
