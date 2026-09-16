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
     * Self-healing backfill for a faculty member who was hired (or reactivated)
     * AFTER a requirement was published: publishing snapshots the audience at
     * that moment, so a later hire has no submission row and the requirement is
     * invisible to them. Called at the start of
     * FacultyController::requirements() so a new hire's checklist fills in on
     * first visit. INSERT IGNORE + NOT EXISTS make this safe to call every time
     * (idempotent) — it only ever adds rows for requirements this faculty
     * member doesn't already have one for. Returns the number of rows actually
     * inserted.
     *
     * AUDIENCE-AWARE (FR-35): the WHERE clause re-evaluates each requirement's
     * audience against THIS faculty member, so a program- or individually-
     * targeted requirement is not silently handed to everyone who visits the
     * page. The three arms mirror requirements.applies_to exactly:
     *   'all_faculty' — always included
     *   'program'     — only when the requirement's target program is this
     *                   faculty member's program (a NULL program_id matches
     *                   nothing, which is correct: an unassigned account is
     *                   not in any program)
     *   'individual'  — only when a requirement_targets row names them
     * Resolved in SQL rather than in PHP so the set can never drift from what
     * the publish step computed.
     *
     * DELIBERATE: this only ever ADDS. Changing a faculty member's program does
     * not retroactively rewrite already-published submissions — rows for their
     * old program stay, and rows for the new one appear on their next visit.
     * Deleting the old rows would destroy uploaded work and its review history
     * to tidy up a bookkeeping change, which is far worse than a stale row the
     * Secretary can see and reason about on the monitoring board.
     */
    public static function backfillForFaculty(int $facultyId, int $periodId): int
    {
        $stmt = self::pdo()->prepare(
            "INSERT IGNORE INTO submissions (requirement_id, faculty_id, status, current_version, updated_at)
             SELECT r.requirement_id, u.user_id, 'Pending', 0, NOW()
             FROM requirements r
             CROSS JOIN users u
             WHERE u.user_id = :faculty_id
               AND r.period_id = :period_id
               AND (
                    r.applies_to = 'all_faculty'
                    OR (r.applies_to = 'program'
                        AND r.target_program_id IS NOT NULL
                        AND r.target_program_id = u.program_id)
                    OR (r.applies_to = 'individual'
                        AND EXISTS (
                            SELECT 1 FROM requirement_targets rt
                            WHERE rt.requirement_id = r.requirement_id
                              AND rt.faculty_id = u.user_id
                        ))
               )
               AND NOT EXISTS (
                   SELECT 1 FROM submissions s
                   WHERE s.requirement_id = r.requirement_id AND s.faculty_id = u.user_id
               )"
        );
        $stmt->execute([
            ':faculty_id' => $facultyId,
            ':period_id'  => $periodId,
        ]);

        return $stmt->rowCount();
    }

    /**
     * A faculty member's submissions for a period, joined to the requirement +
     * document type, with the current version's file (if any) for the
     * "My Requirements" checklist (FR-6). `last_comment` is the most recent
     * reviewer comment on record for the submission (via a correlated
     * subquery on reviews) — only meaningful/shown by the view when status is
     * Revised (FR-15), so faculty know what to fix before resubmitting.
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
     * (FR-12). No per-reviewer filter: the Secretary sees every item.
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
     * anything. The `AND current_version = :expected_version` clause guards a
     * second race: a reviewer who opened an older version (e.g. it was
     * returned, faculty re-uploaded a new version, and the submission is
     * Submitted again before the reviewer's decision lands) must not approve
     * a version they never actually saw. Returns the number of rows affected
     * — the caller must treat 0 as "already decided, or the version changed
     * since this reviewer opened it" and roll back rather than also
     * inserting a reviews row.
     */
    public static function markReviewed(int $submissionId, string $newStatus, int $expectedVersion): int
    {
        $stmt = self::pdo()->prepare(
            "UPDATE submissions
             SET status = :status
             WHERE submission_id = :id AND status = 'Submitted' AND current_version = :expected_version"
        );
        $stmt->execute([
            ':status'           => $newStatus,
            ':id'               => $submissionId,
            ':expected_version' => $expectedVersion,
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
     * The requirement `title` is joined in so the post-upload notification to
     * the reviewers (FR-21) can name the document without a second query.
     *
     * @return array{submission_id:int,requirement_id:int,status:string,current_version:int,title:string}|null
     */
    public static function findOwned(int $submissionId, int $facultyId): ?array
    {
        $stmt = self::pdo()->prepare(
            'SELECT s.submission_id, s.requirement_id, s.status, s.current_version, r.title
             FROM submissions s
             INNER JOIN requirements r ON r.requirement_id = s.requirement_id
             WHERE s.submission_id = :id AND s.faculty_id = :faculty_id
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
     * Submitted, and stamp submitted_at. Called FIRST inside the same
     * transaction as the new document_files row (FacultyController::upload()),
     * before the file row is inserted. The `AND status IN (...)` clause is an
     * atomic concurrency guard against a double-upload race (TOCTOU): if two
     * concurrent uploads for the same submission both pass the earlier
     * findOwned() status check, only the first UPDATE here actually changes
     * anything. Returns the number of rows affected — the caller must treat 0
     * as "someone else already uploaded/changed this" and roll back without
     * inserting a document_files row.
     */
    public static function markSubmitted(int $submissionId, int $newVersion): int
    {
        $stmt = self::pdo()->prepare(
            "UPDATE submissions
             SET status = 'Submitted', current_version = :version, submitted_at = NOW()
             WHERE submission_id = :id AND status IN ('Pending', 'Revised')"
        );
        $stmt->execute([
            ':version' => $newVersion,
            ':id'      => $submissionId,
        ]);

        return $stmt->rowCount();
    }

    /**
     * Submission counts by status for a faculty member's active-period
     * requirements, for the faculty dashboard summary. Every status key is
     * always present, defaulting to 0.
     *
     * @return array{Pending:int,Submitted:int,Approved:int,Revised:int}
     */
    public static function statusCountsForFaculty(int $facultyId, int $periodId): array
    {
        $counts = [
            'Pending'   => 0,
            'Submitted' => 0,
            'Approved'  => 0,
            'Revised'   => 0,
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
     * @return array{Pending:int,Submitted:int,Approved:int,Revised:int}
     */
    public static function statusCountsForPeriod(int $periodId): array
    {
        $counts = [
            'Pending'   => 0,
            'Submitted' => 0,
            'Approved'  => 0,
            'Revised'   => 0,
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
     * overdue. `$today` is computed by the caller from PHP's `date('Y-m-d')`
     * and bound rather than compared via SQL `CURDATE()` — the monitoring
     * matrix and faculty checklist both derive "today" from PHP, so this
     * must use the same clock or the figures can silently disagree under a
     * PHP/MySQL timezone skew.
     */
    public static function overdueCountForPeriod(int $periodId, string $today): int
    {
        $stmt = self::pdo()->prepare(
            "SELECT COUNT(*) AS total
             FROM submissions s
             INNER JOIN requirements r ON r.requirement_id = s.requirement_id
             WHERE r.period_id = :period_id
               AND r.deadline IS NOT NULL
               AND r.deadline < :today
               AND s.status <> 'Approved'"
        );
        $stmt->execute([':period_id' => $periodId, ':today' => $today]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Every submission in a period as a flat {faculty_id, requirement_id,
     * status} list — the raw material for the admin monitoring matrix
     * (FR-17). The controller/view builds the [faculty_id][requirement_id]
     * lookup and pairs it with Requirement::allForPeriod() columns and
     * User::facultyForPeriod() rows.
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
     * Faculty account that has at least one submission in the period, with a
     * status-count breakdown. Deactivated faculty are DELIBERATELY included
     * (no `u.status = 'active'` filter) so this report always agrees with the
     * other admin figures on the page (aggregate cards, per-document-type
     * report, Submission Search) — these reports go to accreditors, and a
     * deactivated faculty's historical submissions must not silently vanish
     * from just this one table. Faculty with zero submissions in the period
     * (e.g. no requirements ever targeted them) are omitted rather than shown
     * as all-zero rows. The controller/view computes the compliance
     * percentage (Approved / total).
     *
     * @return list<array{user_id:int,faculty_name:string,total:int,pending:int,submitted:int,approved:int,revised:int}>
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
                    SUM(CASE WHEN s.status = 'Revised' THEN 1 ELSE 0 END) AS revised
             FROM submissions s
             INNER JOIN requirements r ON r.requirement_id = s.requirement_id
             INNER JOIN users u ON u.user_id = s.faculty_id
             INNER JOIN roles ro ON ro.role_id = u.role_id
             WHERE r.period_id = :period_id AND ro.role_name = 'Faculty'
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
     * @return list<array{doc_type_id:int,doc_type_name:string,total:int,pending:int,submitted:int,approved:int,revised:int}>
     */
    public static function completionByDocumentType(int $periodId): array
    {
        $stmt = self::pdo()->prepare(
            "SELECT dt.doc_type_id, dt.name AS doc_type_name,
                    COUNT(*) AS total,
                    SUM(CASE WHEN s.status = 'Pending' THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN s.status = 'Submitted' THEN 1 ELSE 0 END) AS submitted,
                    SUM(CASE WHEN s.status = 'Approved' THEN 1 ELSE 0 END) AS approved,
                    SUM(CASE WHEN s.status = 'Revised' THEN 1 ELSE 0 END) AS revised
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

    /**
     * Every submission belonging to one academic period — the read-only
     * archive listing (FR-33). Pass $facultyId to scope the result to a single
     * faculty member: that is how a Faculty user browsing the archive sees
     * only their own history, enforced in SQL rather than by filtering after
     * the fact.
     *
     * Works for ANY period, active or not. The archive UI only offers closed
     * periods, but nothing here depends on that, so the same query can back a
     * future "view this period" screen without change.
     *
     * @return list<array{submission_id:int,status:string,current_version:int,updated_at:string,title:string,deadline:?string,doc_type_name:string,faculty_name:string,file_id:?int,file_name:?string}>
     */
    public static function archiveForPeriod(int $periodId, ?int $facultyId = null): array
    {
        $sql = "SELECT s.submission_id, s.status, s.current_version, s.updated_at,
                       r.title, r.deadline,
                       dt.name AS doc_type_name,
                       CONCAT(u.first_name, ' ', u.last_name) AS faculty_name,
                       f.file_id, f.file_name
                FROM submissions s
                INNER JOIN requirements r ON r.requirement_id = s.requirement_id
                INNER JOIN document_types dt ON dt.doc_type_id = r.doc_type_id
                INNER JOIN users u ON u.user_id = s.faculty_id
                LEFT JOIN document_files f ON f.submission_id = s.submission_id AND f.version_no = s.current_version
                WHERE r.period_id = :period_id";

        $params = [':period_id' => $periodId];

        if ($facultyId !== null) {
            $sql .= ' AND s.faculty_id = :faculty_id';
            $params[':faculty_id'] = $facultyId;
        }

        $sql .= ' ORDER BY u.last_name ASC, u.first_name ASC, r.deadline ASC, r.title ASC';

        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * The academic period a submission belongs to, reached through its
     * requirement. Used by the document-detail page (FR-11) to show which
     * period the item came from, and to tell an archived item from a live one.
     *
     * @return array{period_id:int,school_year:string,semester:string,label:?string,is_active:int}|null
     */
    public static function periodFor(int $submissionId): ?array
    {
        $stmt = self::pdo()->prepare(
            'SELECT p.period_id, p.school_year, p.semester, p.label, p.is_active
             FROM submissions s
             INNER JOIN requirements r ON r.requirement_id = s.requirement_id
             INNER JOIN academic_periods p ON p.period_id = r.period_id
             WHERE s.submission_id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $submissionId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** The submissions.status ENUM values, in schema order. */
    private const STATUSES = ['Pending', 'Submitted', 'Approved', 'Revised'];

    private static function pdo(): PDO
    {
        static $config = null;
        if ($config === null) {
            $config = require dirname(__DIR__, 2) . '/config/config.php';
        }

        return Database::connection($config['db']);
    }
}
