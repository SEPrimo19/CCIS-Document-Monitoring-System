<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;
use Throwable;

/**
 * requirements table access for administrator requirement publishing (FR-28)
 * and its audience targeting (FR-35).
 *
 * A requirement's audience is `applies_to` plus one payload, and which payload
 * is read depends on the value:
 *
 *   'all_faculty' — no payload; every active Faculty account.
 *   'program'     — requirements.target_program_id names one program; every
 *                   active Faculty account assigned to it.
 *   'individual'  — the requirement_targets rows list the chosen faculty.
 *
 * The audience is stored, not just applied once at publish time, because
 * Submission::backfillForFaculty() has to re-evaluate it later for faculty
 * hired after the requirement was published.
 */
final class Requirement
{
    /**
     * Insert a new requirement for the given period. Returns the new
     * requirement_id.
     *
     * $appliesTo and $targetProgramId are written as given: the CALLER
     * (RequirementController::store()) is responsible for having validated
     * them against the enum and against a real, active program. This method
     * is not a validation boundary, and the FK on target_program_id is the
     * last line of defence, not the first.
     */
    public static function create(
        int $docTypeId,
        int $periodId,
        string $title,
        ?string $description,
        ?string $deadline,
        int $createdBy,
        string $appliesTo,
        ?int $targetProgramId
    ): int {
        $stmt = self::pdo()->prepare(
            'INSERT INTO requirements
                (doc_type_id, period_id, title, description, applies_to, target_program_id, deadline, created_by)
             VALUES
                (:doc_type_id, :period_id, :title, :description, :applies_to, :target_program_id, :deadline, :created_by)'
        );
        $stmt->execute([
            ':doc_type_id'       => $docTypeId,
            ':period_id'         => $periodId,
            ':title'             => $title,
            ':description'       => $description,
            ':applies_to'        => $appliesTo,
            ':target_program_id' => $targetProgramId,
            ':deadline'          => $deadline,
            ':created_by'        => $createdBy,
        ]);

        return (int) self::pdo()->lastInsertId();
    }

    /**
     * Record the individually-picked audience of a requirement (FR-35), one
     * row per faculty member. Only meaningful when applies_to='individual';
     * the caller must already have narrowed $facultyIds to real, active
     * Faculty accounts (User::filterActiveFacultyIds()).
     *
     * INSERT IGNORE so a repeated id in the POST collapses onto the composite
     * primary key instead of raising a duplicate-key error. Joins the caller's
     * transaction when one is open — PDO has no true nested transactions — so
     * the targets and the requirement row commit together or not at all.
     *
     * @param list<int> $facultyIds
     * @return int rows actually inserted
     */
    public static function addTargets(int $requirementId, array $facultyIds): int
    {
        if ($facultyIds === []) {
            return 0;
        }

        $pdo = self::pdo();
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO requirement_targets (requirement_id, faculty_id)
             VALUES (:requirement_id, :faculty_id)'
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
                    ':faculty_id'     => (int) $facultyId,
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
     * Every requirement for a period, joined to its document-type name, with a
     * submitted/total progress count for the admin list ("2/5 submitted") and
     * the audience it was published to (FR-35).
     * "Submitted" here means any submission whose status has moved past
     * Pending (Submitted, Approved, or Revised all count).
     *
     * `target_program_code` is the program named by a 'program' requirement;
     * `target_count` is how many faculty a 'individual' requirement names.
     * Both are NULL/0 for the audience kinds that do not use them. The
     * requirement_targets count comes from a correlated subquery rather than a
     * second LEFT JOIN: joining two one-to-many tables at once would multiply
     * the submissions rows and inflate the progress counts.
     *
     * @return list<array{requirement_id:int,title:string,description:?string,deadline:?string,created_at:string,doc_type_name:string,applies_to:string,target_program_code:?string,target_count:int,total_count:int,submitted_count:int}>
     */
    public static function allForPeriod(int $periodId): array
    {
        $stmt = self::pdo()->prepare(
            "SELECT r.requirement_id, r.title, r.description, r.deadline, r.created_at,
                    dt.name AS doc_type_name,
                    r.applies_to,
                    p.code AS target_program_code,
                    (SELECT COUNT(*) FROM requirement_targets rt
                      WHERE rt.requirement_id = r.requirement_id) AS target_count,
                    COUNT(s.submission_id) AS total_count,
                    SUM(CASE WHEN s.status <> 'Pending' THEN 1 ELSE 0 END) AS submitted_count
             FROM requirements r
             INNER JOIN document_types dt ON dt.doc_type_id = r.doc_type_id
             LEFT JOIN programs p ON p.program_id = r.target_program_id
             LEFT JOIN submissions s ON s.requirement_id = r.requirement_id
             WHERE r.period_id = :period_id
             GROUP BY r.requirement_id, r.title, r.description, r.deadline, r.created_at,
                      dt.name, r.applies_to, p.code
             ORDER BY r.created_at DESC"
        );
        $stmt->execute([':period_id' => $periodId]);

        return $stmt->fetchAll();
    }

    /**
     * @return array{requirement_id:int,doc_type_id:int,period_id:int,title:string,description:?string,applies_to:string,target_program_id:?int,deadline:?string,created_by:?int,created_at:string}|null
     */
    public static function find(int $id): ?array
    {
        $stmt = self::pdo()->prepare(
            'SELECT requirement_id, doc_type_id, period_id, title, description,
                    applies_to, target_program_id, deadline, created_by, created_at
             FROM requirements
             WHERE requirement_id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
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
