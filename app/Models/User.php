<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * users table finders. Read-only helpers needed by Auth; account management
 * (create/edit/deactivate/reset credentials — FR-26) is added with the Admin
 * user-management feature in Phase 4.
 */
final class User
{
    /**
     * Look up an active user by email, including their role name, for login.
     *
     * @return array{user_id:int,first_name:string,last_name:string,email:string,password_hash:string,role_name:string}|null
     */
    public static function findActiveByEmail(string $email): ?array
    {
        $stmt = self::pdo()->prepare(
            'SELECT u.user_id, u.first_name, u.last_name, u.email, u.password_hash, r.role_name
             FROM users u
             INNER JOIN roles r ON r.role_id = u.role_id
             WHERE u.email = :email AND u.status = "active"
             LIMIT 1'
        );
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array{user_id:int,first_name:string,last_name:string,email:string,status:string,role_name:string}|null
     */
    public static function findById(int $userId): ?array
    {
        $stmt = self::pdo()->prepare(
            'SELECT u.user_id, u.first_name, u.last_name, u.email, u.status, r.role_name
             FROM users u
             INNER JOIN roles r ON r.role_id = u.role_id
             WHERE u.user_id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public static function touchLastLogin(int $userId): void
    {
        $stmt = self::pdo()->prepare('UPDATE users SET last_login = NOW() WHERE user_id = :id');
        $stmt->execute([':id' => $userId]);
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
