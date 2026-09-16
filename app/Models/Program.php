<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * programs table access (FR-36) — the college's academic programs, kept as
 * reference data rather than the free-text users.program_dept column this
 * replaces. Two screens read it: the Secretary's user form, which assigns a
 * faculty account its program, and the requirement form, whose "By program"
 * audience (FR-35) targets one.
 *
 * Read-only from the application: there is no program CRUD screen in this
 * slice, so the four seeded rows are maintained in database/seed.sql. Adding
 * one is a seed/migration change, which is why nothing here writes.
 */
final class Program
{
    /**
     * Every active program, ordered by code, for the <select> controls on the
     * user form and the requirement form. Inactive programs are excluded so a
     * retired program can never be assigned or targeted afresh — existing
     * rows that still reference it keep working, since the FK is untouched.
     *
     * @return list<array{program_id:int,code:string,name:string}>
     */
    public static function allActive(): array
    {
        $stmt = self::pdo()->query(
            'SELECT program_id, code, name
             FROM programs
             WHERE is_active = 1
             ORDER BY code ASC'
        );

        return $stmt->fetchAll();
    }

    /**
     * True when the id names a program that exists AND is active. The
     * authoritative server-side check behind the "By program" audience: the
     * posted program_id is re-checked in SQL rather than trusted from the
     * form, so a forged or stale id cannot publish a requirement against a
     * retired program.
     */
    public static function isActive(int $programId): bool
    {
        $stmt = self::pdo()->prepare(
            'SELECT 1 FROM programs WHERE program_id = :id AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([':id' => $programId]);

        return $stmt->fetch() !== false;
    }

    /**
     * One program, active or not, for labelling an existing row (e.g. a
     * requirement published against a program that has since been retired).
     *
     * @return array{program_id:int,code:string,name:string,is_active:int}|null
     */
    public static function find(int $programId): ?array
    {
        $stmt = self::pdo()->prepare(
            'SELECT program_id, code, name, is_active
             FROM programs
             WHERE program_id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $programId]);
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
