<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * requirements table access for administrator requirement publishing (FR-28).
 * Audience is fixed to all faculty for this slice — no program/individual
 * targeting yet — so applies_to is always written as 'all_faculty'.
 */
final class Requirement
{
    /**
     * Insert a new requirement for the given period. Returns the new requirement_id.
     */
    public static function create(
        int $docTypeId,
        int $periodId,
        string $title,
        ?string $description,
        ?string $deadline,
        int $createdBy
    ): int {
        $stmt = self::pdo()->prepare(
            "INSERT INTO requirements (doc_type_id, period_id, title, description, applies_to, deadline, created_by)
             VALUES (:doc_type_id, :period_id, :title, :description, 'all_faculty', :deadline, :created_by)"
        );
        $stmt->execute([
            ':doc_type_id' => $docTypeId,
            ':period_id'   => $periodId,
            ':title'       => $title,
            ':description' => $description,
            ':deadline'    => $deadline,
            ':created_by'  => $createdBy,
        ]);

        return (int) self::pdo()->lastInsertId();
    }

    /**
     * Every requirement for a period, joined to its document-type name, with a
     * submitted/total progress count for the admin list ("2/5 submitted").
     * "Submitted" here means any submission whose status has moved past
     * Pending (Submitted, Approved, or Returned-for-revision all count).
     *
     * @return list<array{requirement_id:int,title:string,description:?string,deadline:?string,created_at:string,doc_type_name:string,total_count:int,submitted_count:int}>
     */
    public static function allForPeriod(int $periodId): array
    {
        $stmt = self::pdo()->prepare(
            "SELECT r.requirement_id, r.title, r.description, r.deadline, r.created_at,
                    dt.name AS doc_type_name,
                    COUNT(s.submission_id) AS total_count,
                    SUM(CASE WHEN s.status <> 'Pending' THEN 1 ELSE 0 END) AS submitted_count
             FROM requirements r
             INNER JOIN document_types dt ON dt.doc_type_id = r.doc_type_id
             LEFT JOIN submissions s ON s.requirement_id = r.requirement_id
             WHERE r.period_id = :period_id
             GROUP BY r.requirement_id, r.title, r.description, r.deadline, r.created_at, dt.name
             ORDER BY r.created_at DESC"
        );
        $stmt->execute([':period_id' => $periodId]);

        return $stmt->fetchAll();
    }

    /**
     * @return array{requirement_id:int,doc_type_id:int,period_id:int,title:string,description:?string,applies_to:string,deadline:?string,created_by:?int,created_at:string}|null
     */
    public static function find(int $id): ?array
    {
        $stmt = self::pdo()->prepare(
            'SELECT requirement_id, doc_type_id, period_id, title, description, applies_to, deadline, created_by, created_at
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
