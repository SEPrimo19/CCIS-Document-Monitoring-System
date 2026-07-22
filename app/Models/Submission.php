<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;
use Throwable;

/**
 * submissions table access. Rows are created eagerly when an administrator
 * publishes a requirement (FR-28): one Pending row per targeted faculty,
 * enforced unique by UNIQUE(requirement_id, faculty_id).
 */
final class Submission
{
    /**
     * Insert one Pending submission per faculty id for a requirement. Uses
     * INSERT IGNORE so a faculty id that already has a row for this
     * requirement (e.g. a re-run) is silently skipped rather than raising a
     * duplicate-key error on UNIQUE(requirement_id, faculty_id). Returns the
     * number of rows actually inserted (excludes any ignored duplicates).
     *
     * Runs inside its own transaction so the batch is atomic when called on
     * its own. If the caller (RequirementController::store()) already has a
     * transaction open spanning the requirement insert too, this joins it
     * instead of nesting — PDO has no true nested transactions.
     *
     * @param list<int> $facultyIds
     */
    public static function createPendingForFaculty(int $requirementId, array $facultyIds): int
    {
        if ($facultyIds === []) {
            return 0;
        }

        $pdo = self::pdo();
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO submissions (requirement_id, faculty_id, status, current_version, updated_at)
             VALUES (:requirement_id, :faculty_id, 'Pending', 0, NOW())"
        );

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $inserted = 0;
            foreach ($facultyIds as $facultyId) {
                $stmt->execute([
                    ':requirement_id' => $requirementId,
                    ':faculty_id'     => $facultyId,
                ]);
                $inserted += $stmt->rowCount();
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return $inserted;
        } catch (Throwable $e) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * A faculty member's submissions for a period, joined to the requirement +
     * document type, with the current version's file (if any) for the
     * "My Requirements" checklist (FR-6). `last_comment` is the most recent
     * reviewer comment on record for the submission (via a correlated
     * subquery on reviews) — only meaningful/shown by the view when status is
     * Returned-for-revision (FR-15), so faculty know what to fix before
     * resubmitting.
     *
     * @return list<array{submission_id:int,status:string,current_version:int,updated_at:string,title:string,deadline:?string,doc_type_name:string,file_id:?int,file_name:?string,last_comment:?string}>
     */
    public static function checklistForFaculty(int $facultyId, int $periodId): array
    {
        $stmt = self::pdo()->prepare(
            "SELECT s.submission_id, s.status, s.current_version, s.updated_at,
                    r.title, r.deadline,
                    dt.name AS doc_type_name,
                    f.file_id, f.file_name,
                    (SELECT rv.comments
                     FROM reviews rv
                     WHERE rv.submission_id = s.submission_id
                     ORDER BY rv.reviewed_at DESC, rv.review_id DESC
                     LIMIT 1) AS last_comment
             FROM submissions s
             INNER JOIN requirements r ON r.requirement_id = s.requirement_id
             INNER JOIN document_types dt ON dt.doc_type_id = r.doc_type_id
             LEFT JOIN document_files f ON f.submission_id = s.submission_id AND f.version_no = s.current_version
             WHERE s.faculty_id = :faculty_id AND r.period_id = :period_id
             ORDER BY r.deadline ASC"
        );
        $stmt->execute([
            ':faculty_id' => $facultyId,
            ':period_id'  => $periodId,
        ]);

        return $stmt->fetchAll();
    }

    /**
     * Every Submitted submission for a period — the shared reviewer queue
     * (FR-12). No reviewer filter: any Reviewer/Approver sees every item.
     * Optionally narrowed to one document type. Oldest submitted first, so
     * the queue works like a FIFO.
     *
     * @return list<array{submission_id:int,status:string,current_version:int,submitted_at:?string,title:string,doc_type_id:int,doc_type_name:string,faculty_name:string,file_id:?int}>
     */
    public static function queueForReview(int $periodId, ?int $docTypeId): array
    {
        $sql = "SELECT s.submission_id, s.status, s.current_version, s.submitted_at,
                       r.title,
                       dt.doc_type_id, dt.name AS doc_type_name,
                       CONCAT(u.first_name, ' ', u.last_name) AS faculty_name,
                       f.file_id
                FROM submissions s
                INNER JOIN requirements r ON r.requirement_id = s.requirement_id
                INNER JOIN document_types dt ON dt.doc_type_id = r.doc_type_id
                INNER JOIN users u ON u.user_id = s.faculty_id
                LEFT JOIN document_files f ON f.submission_id = s.submission_id AND f.version_no = s.current_version
                WHERE s.status = 'Submitted' AND r.period_id = :period_id";

        $params = [':period_id' => $periodId];

        if ($docTypeId !== null) {
            $sql .= ' AND dt.doc_type_id = :doc_type_id';
            $params[':doc_type_id'] = $docTypeId;
        }

        $sql .= ' ORDER BY s.submitted_at ASC';

        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * One submission for the review detail/decision page, with the
     * requirement, document type, faculty name, and current-version file —
     * no ownership restriction, since any reviewer may act on it (shared
     * queue). Null if the submission doesn't exist.
     *
     * @return array{submission_id:int,status:string,current_version:int,submitted_at:?string,updated_at:string,title:string,description:?string,deadline:?string,doc_type_name:string,faculty_name:string,file_id:?int,file_name:?string,faculty_id:int}|null
     */
    public static function findForReview(int $submissionId): ?array
    {
        $stmt = self::pdo()->prepare(
            "SELECT s.submission_id, s.status, s.current_version, s.submitted_at, s.updated_at,
                    r.title, r.description, r.deadline,
                    dt.name AS doc_type_name,
                    CONCAT(u.first_name, ' ', u.last_name) AS faculty_name,
                    f.file_id, f.file_name, s.faculty_id
             FROM submissions s
             INNER JOIN requirements r ON r.requirement_id = s.requirement_id
             INNER JOIN document_types dt ON dt.doc_type_id = r.doc_type_id
             INNER JOIN users u ON u.user_id = s.faculty_id
             LEFT JOIN document_files f ON f.submission_id = s.submission_id AND f.version_no = s.current_version
             WHERE s.submission_id = :id
             LIMIT 1"
        );
        $stmt->execute([':id' => $submissionId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Record a reviewer's decision on a submission. The `AND status =
     * 'Submitted'` clause is an atomic concurrency guard: it only ever
     * affects a row that is still awaiting review, so if two reviewers race
     * on the same shared-queue item, only the first UPDATE actually changes
     * anything. Returns the number of rows affected — the caller must treat
     * 0 as "someone else already decided this" and roll back rather than
     * also inserting a reviews row.
     */
    public static function markReviewed(int $submissionId, string $newStatus): int
    {
        $stmt = self::pdo()->prepare(
            "UPDATE submissions
             SET status = :status
             WHERE submission_id = :id AND status = 'Submitted'"
        );
        $stmt->execute([
            ':status' => $newStatus,
            ':id'     => $submissionId,
        ]);

        return $stmt->rowCount();
    }

    /**
     * Count of submissions currently awaiting review (status='Submitted') in
     * a period, for the reviewer dashboard's summary card.
     */
    public static function awaitingReviewCount(int $periodId): int
    {
        $stmt = self::pdo()->prepare(
            "SELECT COUNT(*) AS total
             FROM submissions s
             INNER JOIN requirements r ON r.requirement_id = s.requirement_id
             WHERE s.status = 'Submitted' AND r.period_id = :period_id"
        );
        $stmt->execute([':period_id' => $periodId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * The submission ONLY if it belongs to the given faculty id — the upload
     * authorization guard (IDOR protection). Returns null both when the
     * submission doesn't exist and when it belongs to someone else, so a
     * caller can never distinguish the two.
     *
     * @return array{submission_id:int,requirement_id:int,status:string,current_version:int}|null
     */
    public static function findOwned(int $submissionId, int $facultyId): ?array
    {
        $stmt = self::pdo()->prepare(
            'SELECT submission_id, requirement_id, status, current_version
             FROM submissions
             WHERE submission_id = :id AND faculty_id = :faculty_id
             LIMIT 1'
        );
        $stmt->execute([
            ':id'         => $submissionId,
            ':faculty_id' => $facultyId,
        ]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Record a fresh upload: advance current_version, flip status to
     * Submitted, and stamp submitted_at. Called inside the same transaction
     * as the new document_files row (FacultyController::upload()).
     */
    public static function markSubmitted(int $submissionId, int $newVersion): void
    {
        $stmt = self::pdo()->prepare(
            "UPDATE submissions
             SET status = 'Submitted', current_version = :version, submitted_at = NOW()
             WHERE submission_id = :id"
        );
        $stmt->execute([
            ':version' => $newVersion,
            ':id'      => $submissionId,
        ]);
    }

    /**
     * Submission counts by status for a faculty member's active-period
     * requirements, for the faculty dashboard summary. Every status key is
     * always present, defaulting to 0.
     *
     * @return array{Pending:int,Submitted:int,Approved:int,'Returned-for-revision':int}
     */
    public static function statusCountsForFaculty(int $facultyId, int $periodId): array
    {
        $counts = [
            'Pending'               => 0,
            'Submitted'             => 0,
            'Approved'              => 0,
            'Returned-for-revision' => 0,
        ];

        $stmt = self::pdo()->prepare(
            'SELECT s.status, COUNT(*) AS total
             FROM submissions s
             INNER JOIN requirements r ON r.requirement_id = s.requirement_id
             WHERE s.faculty_id = :faculty_id AND r.period_id = :period_id
             GROUP BY s.status'
        );
        $stmt->execute([
            ':faculty_id' => $facultyId,
            ':period_id'  => $periodId,
        ]);

        foreach ($stmt->fetchAll() as $row) {
            $counts[$row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Submission counts by status across ALL faculty for a period, for the
     * admin monitoring board's figures (FR-18). Every status key is always
     * present, defaulting to 0.
     *
     * @return array{Pending:int,Submitted:int,Approved:int,'Returned-for-revision':int}
     */
    public static function statusCountsForPeriod(int $periodId): array
    {
        $counts = [
            'Pending'               => 0,
            'Submitted'             => 0,
            'Approved'              => 0,
            'Returned-for-revision' => 0,
        ];

        $stmt = self::pdo()->prepare(
            'SELECT s.status, COUNT(*) AS total
             FROM submissions s
             INNER JOIN requirements r ON r.requirement_id = s.requirement_id
             WHERE r.period_id = :period_id
             GROUP BY s.status'
        );
        $stmt->execute([':period_id' => $periodId]);

        foreach ($stmt->fetchAll() as $row) {
            $counts[$row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Count of submissions in a period that are past their requirement's
     * deadline and not yet Approved (FR-20). A NULL deadline never counts as
     * overdue.
     */
    public static function overdueCountForPeriod(int $periodId): int
    {
        $stmt = self::pdo()->prepare(
            "SELECT COUNT(*) AS total
             FROM submissions s
             INNER JOIN requirements r ON r.requirement_id = s.requirement_id
             WHERE r.period_id = :period_id
               AND r.deadline IS NOT NULL
               AND r.deadline < CURDATE()
               AND s.status <> 'Approved'"
        );
        $stmt->execute([':period_id' => $periodId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Every submission in a period as a flat {faculty_id, requirement_id,
     * status} list — the raw material for the admin monitoring matrix
     * (FR-17). The controller/view builds the [faculty_id][requirement_id]
     * lookup and pairs it with Requirement::allForPeriod() columns and
     * User::activeFaculty() rows.
     *
     * @return list<array{faculty_id:int,requirement_id:int,status:string}>
     */
    public static function gridForPeriod(int $periodId): array
    {
        $stmt = self::pdo()->prepare(
            'SELECT s.faculty_id, s.requirement_id, s.status
             FROM submissions s
             INNER JOIN requirements r ON r.requirement_id = s.requirement_id
             WHERE r.period_id = :period_id'
        );
        $stmt->execute([':period_id' => $periodId]);

        return $stmt->fetchAll();
    }

    /**
     * The valid submissions.status enum values, exposed so callers (e.g. the
     * admin search filter) can validate a status value before using it.
     *
     * @return list<string>
     */
    public static function statuses(): array
    {
        return self::STATUSES;
    }

    /**
     * A flat, filterable submissions list for a period — the admin
     * submission search (FR-19). Every filter is optional and, when applied,
     * is bound as a parameter — the WHERE clause is built dynamically but
     * never by concatenating a value into the SQL string. `$status` is
     * re-validated against the enum here (defense in depth: the controller
     * validates it too) and silently ignored if invalid rather than used.
     *
     * @return list<array{submission_id:int,faculty_name:string,title:string,doc_type_id:int,doc_type_name:string,status:string,deadline:?string,submitted_at:?string,file_id:?int}>
     */
    public static function searchForPeriod(int $periodId, ?string $facultyName, ?string $status, ?int $docTypeId): array
    {
        $sql = "SELECT s.submission_id,
                       CONCAT(u.first_name, ' ', u.last_name) AS faculty_name,
                       r.title, dt.doc_type_id, dt.name AS doc_type_name,
                       s.status, r.deadline, s.submitted_at,
                       f.file_id
                FROM submissions s
                INNER JOIN requirements r ON r.requirement_id = s.requirement_id
                INNER JOIN document_types dt ON dt.doc_type_id = r.doc_type_id
                INNER JOIN users u ON u.user_id = s.faculty_id
                LEFT JOIN document_files f ON f.submission_id = s.submission_id AND f.version_no = s.current_version
                WHERE r.period_id = :period_id";

        $params = [':period_id' => $periodId];

        $facultyName = $facultyName !== null ? trim($facultyName) : null;
        if ($facultyName !== null && $facultyName !== '') {
            $sql .= " AND CONCAT(u.first_name, ' ', u.last_name) LIKE :faculty_name";
            $params[':faculty_name'] = '%' . $facultyName . '%';
        }

        if ($status !== null && in_array($status, self::STATUSES, true)) {
            $sql .= ' AND s.status = :status';
            $params[':status'] = $status;
        }

        if ($docTypeId !== null) {
            $sql .= ' AND dt.doc_type_id = :doc_type_id';
            $params[':doc_type_id'] = $docTypeId;
        }

        $sql .= ' ORDER BY u.last_name ASC, u.first_name ASC, r.title ASC';

        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Per-faculty compliance for a period (FR-24, FR-25 reports): one row per
     * active Faculty account that has at least one submission in the period,
     * with a status-count breakdown. Faculty with zero submissions in the
     * period (e.g. no requirements ever targeted them) are omitted rather
     * than shown as all-zero rows. The controller/view computes the
     * compliance percentage (Approved / total).
     *
     * @return list<array{user_id:int,faculty_name:string,total:int,pending:int,submitted:int,approved:int,returned:int}>
     */
    public static function complianceByFaculty(int $periodId): array
    {
        $stmt = self::pdo()->prepare(
            "SELECT u.user_id,
                    CONCAT(u.first_name, ' ', u.last_name) AS faculty_name,
                    COUNT(*) AS total,
                    SUM(CASE WHEN s.status = 'Pending' THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN s.status = 'Submitted' THEN 1 ELSE 0 END) AS submitted,
                    SUM(CASE WHEN s.status = 'Approved' THEN 1 ELSE 0 END) AS approved,
                    SUM(CASE WHEN s.status = 'Returned-for-revision' THEN 1 ELSE 0 END) AS returned
             FROM submissions s
             INNER JOIN requirements r ON r.requirement_id = s.requirement_id
             INNER JOIN users u ON u.user_id = s.faculty_id
             INNER JOIN roles ro ON ro.role_id = u.role_id
             WHERE r.period_id = :period_id AND u.status = 'active' AND ro.role_name = 'Faculty'
             GROUP BY u.user_id, u.first_name, u.last_name
             ORDER BY u.last_name ASC, u.first_name ASC"
        );
        $stmt->execute([':period_id' => $periodId]);

        return $stmt->fetchAll();
    }

    /**
     * Per-document-type completion for a period (FR-24, FR-25 reports): one
     * row per document type actually used by the period's requirements, with
     * a status-count breakdown. Document types with no requirements in the
     * period are omitted. The controller/view computes the completion
     * percentage (Approved / total).
     *
     * @return list<array{doc_type_id:int,doc_type_name:string,total:int,pending:int,submitted:int,approved:int,returned:int}>
     */
    public static function completionByDocumentType(int $periodId): array
    {
        $stmt = self::pdo()->prepare(
            "SELECT dt.doc_type_id, dt.name AS doc_type_name,
                    COUNT(*) AS total,
                    SUM(CASE WHEN s.status = 'Pending' THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN s.status = 'Submitted' THEN 1 ELSE 0 END) AS submitted,
                    SUM(CASE WHEN s.status = 'Approved' THEN 1 ELSE 0 END) AS approved,
                    SUM(CASE WHEN s.status = 'Returned-for-revision' THEN 1 ELSE 0 END) AS returned
             FROM submissions s
             INNER JOIN requirements r ON r.requirement_id = s.requirement_id
             INNER JOIN document_types dt ON dt.doc_type_id = r.doc_type_id
             WHERE r.period_id = :period_id
             GROUP BY dt.doc_type_id, dt.name
             ORDER BY dt.name ASC"
        );
        $stmt->execute([':period_id' => $periodId]);

        return $stmt->fetchAll();
    }

    /** The submissions.status ENUM values, in schema order. */
    private const STATUSES = ['Pending', 'Submitted', 'Approved', 'Returned-for-revision'];

    private static function pdo(): PDO
    {
        static $config = null;
        if ($config === null) {
            $config = require dirname(__DIR__, 2) . '/config/config.php';
        }

        return Database::connection($config['db']);
    }
}
