<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * notifications table access — in-app notifications (FR-21..23).
 *
 * Three triggers, matching FR-21:
 *   - 'status_change' — a reviewer approved or returned a document
 *     (ReviewerController::decide), and a faculty member submitted something
 *     new (FacultyController::upload notifies the reviewers).
 *   - 'deadline'      — a requirement is due soon and still unsubmitted.
 *   - 'pending'       — a requirement is past its deadline and still unsubmitted.
 *
 * **How reminders are generated.** PHP has no scheduler, and requiring a cron
 * job would make the system undeployable on the shared hosting this project
 * targets. So reminders are generated lazily: the faculty dashboard and
 * checklist call the generators on load, and each generator is an
 * INSERT...SELECT whose NOT EXISTS clause suppresses a reminder that already
 * went out for the same submission, of the same type, on the same day. That
 * makes generation idempotent — refreshing the page ten times produces one
 * reminder, not ten — and self-healing, since a user who does not log in for a
 * week simply gets the reminder on their next visit rather than a backlog.
 *
 * "Today" is always a PHP-computed date bound as a parameter, never CURDATE().
 * PHP and MySQL are pinned to the same configured timezone, but binding the
 * date keeps the comparison correct even if that ever drifts.
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
     * Insert the same notification for several users at once — used to tell
     * every active Secretary that a new document is waiting (FR-21). One
     * statement, so a queue of reviewers costs one round trip.
     *
     * @param list<int> $userIds
     * @return int rows inserted
     */
    public static function createForMany(array $userIds, ?int $submissionId, string $type, string $title, string $message): int
    {
        if ($userIds === []) {
            return 0;
        }

        $values = [];
        $params = [];

        // EVERY column gets its own placeholder per row, including the four
        // that hold the same value in all rows. That looks redundant but is
        // required: PDO runs with ATTR_EMULATE_PREPARES => false (see
        // App\Core\Database), and a NATIVE prepared statement cannot reuse one
        // named placeholder across several positions — doing so throws
        // SQLSTATE[HY093] Invalid parameter number as soon as there is more
        // than one row. With a single reviewer each name appears exactly once
        // and the bug is invisible, which is precisely how it would have
        // reached production.
        //
        // Ids are placeholder NAMES built from the loop index and values are
        // bound, so nothing user-controlled is interpolated into SQL.
        foreach (array_values($userIds) as $i => $userId) {
            $values[] = "(:user_{$i}, :sub_{$i}, :type_{$i}, :title_{$i}, :msg_{$i})";
            $params[":user_{$i}"]  = (int) $userId;
            $params[":sub_{$i}"]   = $submissionId;
            $params[":type_{$i}"]  = $type;
            $params[":title_{$i}"] = $title;
            $params[":msg_{$i}"]   = $message;
        }

        $stmt = self::pdo()->prepare(
            'INSERT INTO notifications (user_id, submission_id, type, title, message) VALUES '
            . implode(', ', $values)
        );
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * Notify every faculty member a requirement was just published to that they
     * now have something to submit (FR-21, the "requirement is pending/missing"
     * trigger). One INSERT...SELECT over the Pending submission rows the publish
     * step just created for this requirement, so each notification carries that
     * faculty member's own submission_id — the "View my requirements" link then
     * lands them exactly where they can upload.
     *
     * Called once, at publish time, so no per-day dedup is needed. `:message`
     * appears a single time in the statement text (applied to every selected
     * row), so it is safe under native prepares — unlike a repeated placeholder.
     *
     * @return int notifications created (one per active faculty at publish time)
     */
    public static function createRequirementAssigned(int $requirementId, string $message): int
    {
        $stmt = self::pdo()->prepare(
            "INSERT INTO notifications (user_id, submission_id, type, title, message)
             SELECT s.faculty_id, s.submission_id, 'pending', 'New requirement to submit', :message
             FROM submissions s
             WHERE s.requirement_id = :req AND s.status = 'Pending'"
        );
        $stmt->execute([':message' => $message, ':req' => $requirementId]);

        return $stmt->rowCount();
    }

    /**
     * Raise a "deadline approaching" reminder for every requirement this
     * faculty member still owes that falls due within the next $daysAhead
     * days (FR-21).
     *
     * Only Pending and Revised qualify: a Submitted item is out
     * of the faculty member's hands, and an Approved one is finished — neither
     * is actionable, so reminding about them would be noise.
     *
     * @return int reminders created
     */
    public static function generateDeadlineReminders(int $facultyId, int $periodId, string $today, int $daysAhead = 7): int
    {
        $horizon = date('Y-m-d', strtotime($today . ' +' . max(0, $daysAhead) . ' days'));

        // LEFT JOIN ... IS NULL rather than NOT EXISTS: MySQL refuses to read
        // the INSERT target table from a subquery in the SELECT, but joining it
        // is allowed.
        $stmt = self::pdo()->prepare(
            "INSERT INTO notifications (user_id, submission_id, type, title, message)
             SELECT s.faculty_id,
                    s.submission_id,
                    'deadline',
                    'Deadline approaching',
                    LEFT(CONCAT('\"', r.title, '\" is due on ', DATE_FORMAT(r.deadline, '%b %e, %Y'),
                                '. Please upload your document before the deadline.'), 255)
             FROM submissions s
             INNER JOIN requirements r ON r.requirement_id = s.requirement_id
             LEFT JOIN notifications n
                    ON n.submission_id = s.submission_id
                   AND n.user_id = s.faculty_id
                   AND n.type = 'deadline'
                   AND DATE(n.created_at) = :today_dedup
             WHERE s.faculty_id = :faculty_id
               AND r.period_id = :period_id
               AND s.status IN ('Pending', 'Revised')
               AND r.deadline IS NOT NULL
               AND r.deadline >= :today
               AND r.deadline <= :horizon
               AND n.notification_id IS NULL"
        );
        $stmt->execute([
            ':faculty_id'  => $facultyId,
            ':period_id'   => $periodId,
            ':today'       => $today,
            ':today_dedup' => $today,
            ':horizon'     => $horizon,
        ]);

        return $stmt->rowCount();
    }

    /**
     * Raise a "still outstanding" reminder for every requirement this faculty
     * member owes whose deadline has already passed (FR-21's pending/missing
     * trigger).
     *
     * @return int reminders created
     */
    public static function generateOverdueReminders(int $facultyId, int $periodId, string $today, int $graceDays = 30): int
    {
        // Lower bound on how far past the deadline we keep nagging. Without it,
        // an item left unsubmitted forever would raise a fresh reminder EVERY
        // day for the rest of the academic period — unbounded growth of the
        // notifications table and an ever-noisier centre. After $graceDays past
        // the deadline the item is simply overdue-and-known; the monitoring
        // board and the faculty checklist still show it, so nothing is lost.
        $floor = date('Y-m-d', strtotime($today . ' -' . max(0, $graceDays) . ' days'));

        $stmt = self::pdo()->prepare(
            "INSERT INTO notifications (user_id, submission_id, type, title, message)
             SELECT s.faculty_id,
                    s.submission_id,
                    'pending',
                    'Requirement overdue',
                    LEFT(CONCAT('\"', r.title, '\" was due on ', DATE_FORMAT(r.deadline, '%b %e, %Y'),
                                ' and has not been submitted yet.'), 255)
             FROM submissions s
             INNER JOIN requirements r ON r.requirement_id = s.requirement_id
             LEFT JOIN notifications n
                    ON n.submission_id = s.submission_id
                   AND n.user_id = s.faculty_id
                   AND n.type = 'pending'
                   AND DATE(n.created_at) = :today_dedup
             WHERE s.faculty_id = :faculty_id
               AND r.period_id = :period_id
               AND s.status IN ('Pending', 'Revised')
               AND r.deadline IS NOT NULL
               AND r.deadline < :today
               AND r.deadline >= :floor
               AND n.notification_id IS NULL"
        );
        $stmt->execute([
            ':faculty_id'  => $facultyId,
            ':period_id'   => $periodId,
            ':today'       => $today,
            ':today_dedup' => $today,
            ':floor'       => $floor,
        ]);

        return $stmt->rowCount();
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
     * A user's UNREAD notifications, newest first, for the "Unread" tab. This
     * is a separate query rather than a filter over forUser(): filtering the
     * capped page in PHP would hide unread items that fell past the LIMIT and
     * make the tab disagree with the header badge's true count. $limit is cast
     * to int and inlined — LIMIT can't be bound as a PDO parameter.
     *
     * @return list<array{notification_id:int,user_id:int,submission_id:?int,type:string,title:string,message:string,is_read:int,created_at:string}>
     */
    public static function unreadForUser(int $userId, int $limit = 50): array
    {
        $stmt = self::pdo()->prepare(
            'SELECT notification_id, user_id, submission_id, type, title, message, is_read, created_at
             FROM notifications
             WHERE user_id = :user_id AND is_read = 0
             ORDER BY created_at DESC, notification_id DESC
             LIMIT ' . (int) $limit
        );
        $stmt->execute([':user_id' => $userId]);

        return $stmt->fetchAll();
    }

    /**
     * Total notifications for a user. Used for the "All" tab count, which must
     * report every row — not just the page forUser() returns.
     */
    public static function countForUser(int $userId): int
    {
        $stmt = self::pdo()->prepare(
            'SELECT COUNT(*) FROM notifications WHERE user_id = :user_id'
        );
        $stmt->execute([':user_id' => $userId]);

        return (int) $stmt->fetchColumn();
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
