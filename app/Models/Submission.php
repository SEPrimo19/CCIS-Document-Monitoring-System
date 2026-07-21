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
     * "My Requirements" checklist (FR-6).
     *
     * @return list<array{submission_id:int,status:string,current_version:int,updated_at:string,title:string,deadline:?string,doc_type_name:string,file_id:?int,file_name:?string}>
     */
    public static function checklistForFaculty(int $facultyId, int $periodId): array
    {
        $stmt = self::pdo()->prepare(
            "SELECT s.submission_id, s.status, s.current_version, s.updated_at,
                    r.title, r.deadline,
                    dt.name AS doc_type_name,
                    f.file_id, f.file_name
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

    private static function pdo(): PDO
    {
        static $config = null;
        if ($config === null) {
            $config = require dirname(__DIR__, 2) . '/config/config.php';
        }

        return Database::connection($config['db']);
    }
}
