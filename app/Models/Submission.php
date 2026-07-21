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

    private static function pdo(): PDO
    {
        static $config = null;
        if ($config === null) {
            $config = require dirname(__DIR__, 2) . '/config/config.php';
        }

        return Database::connection($config['db']);
    }
}
