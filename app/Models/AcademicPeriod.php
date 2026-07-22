<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * academic_periods table access. Exactly one period is expected to be active
 * (is_active=1) at a time; requirements are always published against that
 * period (FR-28) — there is no period-selection UI in this slice.
 */
final class AcademicPeriod
{
    /**
     * The single active academic period, or null if none is active.
     *
     * @return array{period_id:int,school_year:string,semester:string,label:?string,start_date:?string,end_date:?string,is_active:int}|null
     */
    public static function active(): ?array
    {
        $stmt = self::pdo()->query(
            'SELECT period_id, school_year, semester, label, start_date, end_date, is_active
             FROM academic_periods
             WHERE is_active = 1
             LIMIT 1'
        );
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array{period_id:int,school_year:string,semester:string,label:?string,start_date:?string,end_date:?string,is_active:int}|null
     */
    public static function find(int $id): ?array
    {
        $stmt = self::pdo()->prepare(
            'SELECT period_id, school_year, semester, label, start_date, end_date, is_active
             FROM academic_periods
             WHERE period_id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Every academic period, newest first — powers the reports period
     * selector (FR-24, FR-25), where an administrator may pull a report for
     * any period, not just the currently active one.
     *
     * @return list<array{period_id:int,school_year:string,semester:string,label:?string,is_active:int}>
     */
    public static function all(): array
    {
        $stmt = self::pdo()->query(
            'SELECT period_id, school_year, semester, label, is_active
             FROM academic_periods
             ORDER BY is_active DESC, school_year DESC, semester DESC'
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
