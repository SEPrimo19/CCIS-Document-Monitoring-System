<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * audit_log access: `record()` (used throughout the app) writes rows, and
 * `search()`/`distinctActions()`/`distinctActors()` back the admin-only audit
 * log view (FR-30, FR-31). `user_id` is NOT NULL with a foreign key to users,
 * so a row can only record actions performed by a real, resolved account —
 * never a failed/anonymous login attempt (see LoginAttempt for that log). The
 * log is append-only by policy: there is deliberately no update/delete here.
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

    /**
     * A flat, filterable audit trail listing — the admin audit log view
     * (FR-30, FR-31). Every filter is optional and, when applied, is bound as
     * a parameter — the WHERE clause is built dynamically but never by
     * concatenating a value into the SQL string. `$dateFrom`/`$dateTo` are
     * bound as full-day boundaries so the end day is included in full.
     * Reverse-chronological (newest first), capped at `$limit` rows —
     * `$limit` is cast to int and inlined since LIMIT can't be bound as a PDO
     * parameter, same as Notification::forUser().
     *
     * @return list<array{log_id:int,action:string,entity_type:string,entity_id:?int,details:?string,ip_address:?string,created_at:string,actor_name:string,actor_role:string}>
     */
    public static function search(?int $userId, ?string $action, ?string $dateFrom, ?string $dateTo, int $limit = 200): array
    {
        $sql = "SELECT a.log_id, a.action, a.entity_type, a.entity_id, a.details, a.ip_address, a.created_at,
                       CONCAT(u.first_name, ' ', u.last_name) AS actor_name, r.role_name AS actor_role
                FROM audit_log a
                INNER JOIN users u ON u.user_id = a.user_id
                INNER JOIN roles r ON r.role_id = u.role_id";

        $params = [];
        $conditions = [];

        if ($userId !== null) {
            $conditions[] = 'a.user_id = :user_id';
            $params[':user_id'] = $userId;
        }

        if ($action !== null) {
            $conditions[] = 'a.action = :action';
            $params[':action'] = $action;
        }

        if ($dateFrom !== null) {
            $conditions[] = 'a.created_at >= :date_from';
            $params[':date_from'] = $dateFrom . ' 00:00:00';
        }

        if ($dateTo !== null) {
            $conditions[] = 'a.created_at <= :date_to';
            $params[':date_to'] = $dateTo . ' 23:59:59';
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY a.created_at DESC, a.log_id DESC LIMIT ' . (int) $limit;

        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Every distinct action value that actually appears in the log, ordered
     * alphabetically — powers the audit log view's action filter dropdown
     * from real data rather than a hard-coded list.
     *
     * @return list<string>
     */
    public static function distinctActions(): array
    {
        $stmt = self::pdo()->query('SELECT DISTINCT action FROM audit_log ORDER BY action ASC');

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Every user who actually appears in the log (as an actor), for the audit
     * log view's user filter dropdown.
     *
     * @return list<array{user_id:int,actor_name:string}>
     */
    public static function distinctActors(): array
    {
        $stmt = self::pdo()->query(
            "SELECT DISTINCT u.user_id, CONCAT(u.first_name, ' ', u.last_name) AS actor_name
             FROM audit_log a
             INNER JOIN users u ON u.user_id = a.user_id
             ORDER BY u.last_name, u.first_name"
        );

        return $stmt->fetchAll();
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
