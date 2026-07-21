<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * reviews table access — one row per reviewer decision (Approve / Return-for-
 * revision) on a submission (FR-13, FR-14). Review routing is a SHARED QUEUE:
 * any Reviewer/Approver may act on any Submitted item, so there is no
 * per-reviewer assignment or ownership check here. reviews.comments is a
 * nullable TEXT column, so an approval with no comment is stored as NULL
 * (never an empty string) — see ReviewerController::decide().
 */
final class Review
{
    /**
     * Insert a review decision. Returns the new review_id.
     */
    public static function create(int $submissionId, int $reviewerId, string $decision, ?string $comments): int
    {
        $stmt = self::pdo()->prepare(
            'INSERT INTO reviews (submission_id, reviewer_id, decision, comments)
             VALUES (:submission_id, :reviewer_id, :decision, :comments)'
        );
        $stmt->execute([
            ':submission_id' => $submissionId,
            ':reviewer_id'   => $reviewerId,
            ':decision'      => $decision,
            ':comments'      => $comments,
        ]);

        return (int) self::pdo()->lastInsertId();
    }

    /**
     * Every review recorded for a submission, newest first, joined to the
     * reviewer's name — for the review detail page's decision history. The
     * most recent row here is also what the faculty checklist surfaces as
     * `last_comment` (see Submission::checklistForFaculty()).
     *
     * @return list<array{review_id:int,decision:string,comments:?string,reviewed_at:string,reviewer_name:string}>
     */
    public static function historyForSubmission(int $submissionId): array
    {
        $stmt = self::pdo()->prepare(
            "SELECT r.review_id, r.decision, r.comments, r.reviewed_at,
                    CONCAT(u.first_name, ' ', u.last_name) AS reviewer_name
             FROM reviews r
             INNER JOIN users u ON u.user_id = r.reviewer_id
             WHERE r.submission_id = :submission_id
             ORDER BY r.reviewed_at DESC, r.review_id DESC"
        );
        $stmt->execute([':submission_id' => $submissionId]);

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
