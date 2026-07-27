<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * roles table access — a small, read-only lookup used by the admin
 * user-management form (FR-26) to populate the role <select> and to
 * validate a submitted role_id against real, existing roles.
 */
final class Role
{
    /**
     * Every role, ordered by role_id, for the user form's role <select>.
     *
     * @return list<array{role_id:int,role_name:string}>
     */
    public static function all(): array
    {
        $stmt = self::pdo()->query('SELECT role_id, role_name FROM roles ORDER BY role_id ASC');

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
