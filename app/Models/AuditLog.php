<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * audit_log inserts. `user_id` is NOT NULL with a foreign key to users, so this
 * can only record actions performed by a real, resolved account — never a
 * failed/anonymous login attempt (see LoginAttempt for that log).
 */
final class AuditLog
{
    public static function record(
        int $userId,
        string $action,
        string $entityType,
        ?int $entityId,
        ?string $details,
        ?string $ip
    ): void {
        $stmt = self::pdo()->prepare(
            'INSERT INTO audit_log (user_id, action, entity_type, entity_id, details, ip_address)
             VALUES (:user_id, :action, :entity_type, :entity_id, :details, :ip)'
        );
        $stmt->execute([
            ':user_id'     => $userId,
            ':action'      => $action,
            ':entity_type' => $entityType,
            ':entity_id'   => $entityId,
            ':details'     => $details,
            ':ip'          => $ip,
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
