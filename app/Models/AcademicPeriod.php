<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * academic_periods table access (FR-29). At most ONE period is active
 * (is_active=1) at a time; requirements are always published against that
 * period (FR-28), and every active-period screen — checklist, review queue,
 * monitoring board — is scoped to it (FR-34).
 *
 * "At most one" is enforced in activate() by a transaction that clears every
 * other active row before setting the chosen one. A purely declarative form is
 * possible (a generated column that is 1 only when is_active=1 and NULL
 * otherwise, plus a UNIQUE index on it — NULLs don't collide, so any number of
 * inactive rows coexist while at most one active row is allowed); it was not
 * used here only to keep the schema simple and the rule visible in one method.
 * Every write path that can set is_active therefore goes through activate().
 *
 * Zero active periods is a legitimate state — between semesters, for example —
 * and every screen handles it with a "no active period" message rather than
 * an error.
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

    /**
     * Every period with the volume of work attached to it, newest first — the
     * admin management list (FR-29). The counts tell an administrator whether
     * a period is safe to leave alone, and give the archive something to say
     * about each past period.
     *
     * @return list<array{period_id:int,school_year:string,semester:string,label:?string,start_date:?string,end_date:?string,is_active:int,requirement_count:int,submission_count:int}>
     */
    public static function allWithCounts(): array
    {
        // Counts come from correlated subqueries rather than two LEFT JOINs:
        // joining both requirements and submissions in one query multiplies the
        // rows together and would inflate every count.
        $stmt = self::pdo()->query(
            'SELECT p.period_id, p.school_year, p.semester, p.label, p.start_date, p.end_date, p.is_active,
                    (SELECT COUNT(*) FROM requirements r WHERE r.period_id = p.period_id) AS requirement_count,
                    (SELECT COUNT(*) FROM submissions s
                       INNER JOIN requirements r2 ON r2.requirement_id = s.requirement_id
                      WHERE r2.period_id = p.period_id) AS submission_count
             FROM academic_periods p
             ORDER BY p.school_year DESC, p.semester DESC'
        );

        return $stmt->fetchAll();
    }

    /**
     * Periods that are NOT the active one and that actually hold work, newest
     * first — the archive browser's period list (FR-33). A period with no
     * requirements has nothing to browse, so it is left out rather than
     * offering an empty year.
     *
     * @return list<array{period_id:int,school_year:string,semester:string,label:?string,is_active:int,requirement_count:int,submission_count:int}>
     */
    public static function archivable(): array
    {
        $stmt = self::pdo()->query(
            'SELECT p.period_id, p.school_year, p.semester, p.label, p.is_active,
                    (SELECT COUNT(*) FROM requirements r WHERE r.period_id = p.period_id) AS requirement_count,
                    (SELECT COUNT(*) FROM submissions s
                       INNER JOIN requirements r2 ON r2.requirement_id = s.requirement_id
                      WHERE r2.period_id = p.period_id) AS submission_count
             FROM academic_periods p
             WHERE p.is_active = 0
               AND EXISTS (SELECT 1 FROM requirements r3 WHERE r3.period_id = p.period_id)
             ORDER BY p.school_year DESC, p.semester DESC'
        );

        return $stmt->fetchAll();
    }

    public static function create(string $schoolYear, string $semester, ?string $label, ?string $startDate, ?string $endDate): int
    {
        $stmt = self::pdo()->prepare(
            'INSERT INTO academic_periods (school_year, semester, label, start_date, end_date, is_active)
             VALUES (:sy, :sem, :label, :start, :end, 0)'
        );
        $stmt->execute([
            ':sy'    => $schoolYear,
            ':sem'   => $semester,
            ':label' => $label,
            ':start' => $startDate,
            ':end'   => $endDate,
        ]);

        return (int) self::pdo()->lastInsertId();
    }

    /**
     * Edit a period's descriptive fields. Deliberately cannot touch is_active —
     * that only ever changes through activate()/deactivate(), so the
     * one-active-period rule has a single enforcement point.
     */
    public static function update(int $id, string $schoolYear, string $semester, ?string $label, ?string $startDate, ?string $endDate): void
    {
        $stmt = self::pdo()->prepare(
            'UPDATE academic_periods
                SET school_year = :sy, semester = :sem, label = :label,
                    start_date = :start, end_date = :end
              WHERE period_id = :id'
        );
        $stmt->execute([
            ':sy'    => $schoolYear,
            ':sem'   => $semester,
            ':label' => $label,
            ':start' => $startDate,
            ':end'   => $endDate,
            ':id'    => $id,
        ]);
    }

    /**
     * Make one period the active one, atomically. Clearing every other row and
     * setting this one happen in a single transaction, so a concurrent
     * activate can never leave two periods active.
     *
     * Returns false when the id does not exist — the clear is rolled back, so
     * the previously active period is left untouched.
     */
    public static function activate(int $id): bool
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();

        try {
            $pdo->exec('UPDATE academic_periods SET is_active = 0 WHERE is_active = 1');

            $stmt = $pdo->prepare('UPDATE academic_periods SET is_active = 1 WHERE period_id = :id');
            $stmt->execute([':id' => $id]);

            // The clear above has already set every row (including this one, if
            // it was the active period) to is_active = 0, so this UPDATE always
            // changes a row that exists. rowCount() is therefore 0 IFF the id
            // does not exist — in which case roll back so the previously active
            // period is left untouched, and report failure.
            if ($stmt->rowCount() === 0) {
                $pdo->rollBack();
                return false;
            }

            $pdo->commit();

            return true;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Close the active period without opening another — the between-semesters
     * state. Screens fall back to their "no active period" message.
     *
     * The `AND is_active = 1` guard makes this report truthfully: it returns
     * true only when it actually closed an active period, so the caller never
     * flashes "period closed" for a period that was already closed (or gone).
     */
    public static function deactivate(int $id): bool
    {
        $stmt = self::pdo()->prepare('UPDATE academic_periods SET is_active = 0 WHERE period_id = :id AND is_active = 1');
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * True when another period already uses this school year + semester pair
     * (the table's UNIQUE key). Checked before writing so the administrator
     * gets a field-level message instead of a duplicate-key 500.
     */
    public static function existsByYearSemester(string $schoolYear, string $semester, ?int $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM academic_periods WHERE school_year = :sy AND semester = :sem';
        $params = [':sy' => $schoolYear, ':sem' => $semester];

        if ($exceptId !== null) {
            $sql .= ' AND period_id <> :except';
            $params[':except'] = $exceptId;
        }

        $stmt = self::pdo()->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * The semester values the column's ENUM accepts.
     *
     * @return list<string>
     */
    public static function semesters(): array
    {
        return ['1st', '2nd', 'Summer'];
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
