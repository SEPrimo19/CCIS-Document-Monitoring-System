<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * notifications table access — in-app notifications (FR-21..23). This slice
 * only creates 'status_change' rows (from ReviewerController::decide()); the
 * 'deadline' and 'pending' types are reserved for a later time-based reminder
 * slice that needs its own dedup/scheduled-generation strategy.
 */
final class Notification
{
    /**
     * Insert one notification. Returns the new notification_id. $type is one
     * of 'deadline'|'pending'|'status_change' (notifications.type ENUM).
     */
    public static function create(int $userId, ?int $submissionId, string $type, string $title, string $message): int
    {
        $stmt = self::pdo()->prepare(
            'INSERT INTO notifications (user_id, submission_id, type, title, message)
             VALUES (:user_id, :submission_id, :type, :title, :message)'
        );
        $stmt->execute([
            ':user_id'       => $userId,
            ':submission_id' => $submissionId,
            ':type'          => $type,
            ':title'         => $title,
            ':message'       => $message,
        ]);

        return (int) self::pdo()->lastInsertId();
    }

    /**
     * A user's notifications, newest first, for the notification center
     * (FR-22). $limit is cast to int and inlined — LIMIT can't be bound as a
     * PDO parameter — so it's injection-safe.
     *
     * @return list<array{notification_id:int,user_id:int,submission_id:?int,type:string,title:string,message:string,is_read:int,created_at:string}>
     */
    public static function forUser(int $userId, int $limit = 50): array
    {
        $stmt = self::pdo()->prepare(
            'SELECT notification_id, user_id, submission_id, type, title, message, is_read, created_at
             FROM notifications
             WHERE user_id = :user_id
             ORDER BY created_at DESC, notification_id DESC
             LIMIT ' . (int) $limit
        );
        $stmt->execute([':user_id' => $userId]);

        return $stmt->fetchAll();
    }

    /**
     * Count of unread notifications for a user, for the header badge (FR-23).
     */
    public static function unreadCount(int $userId): int
    {
        $stmt = self::pdo()->prepare(
            'SELECT COUNT(*) AS total FROM notifications WHERE user_id = :user_id AND is_read = 0'
        );
        $stmt->execute([':user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Mark one notification read. The `AND user_id = :user_id` guard is IDOR
     * protection: a user can only mark their OWN notifications read — a
     * forged id belonging to someone else is a silent no-op.
     */
    public static function markRead(int $notificationId, int $userId): void
    {
        $stmt = self::pdo()->prepare(
            'UPDATE notifications SET is_read = 1 WHERE notification_id = :id AND user_id = :user_id'
        );
        $stmt->execute([
            ':id'      => $notificationId,
            ':user_id' => $userId,
        ]);
    }

    /**
     * Mark every unread notification read for a user.
     */
    public static function markAllRead(int $userId): void
    {
        $stmt = self::pdo()->prepare(
            'UPDATE notifications SET is_read = 1 WHERE user_id = :user_id AND is_read = 0'
        );
        $stmt->execute([':user_id' => $userId]);
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
