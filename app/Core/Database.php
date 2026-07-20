<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Thin PDO factory. Keeps a single connection per request.
 */
final class Database
{
    private static ?PDO $instance = null;

    /**
     * Connection to the application database.
     *
     * @param array{host:string,port:string,name:string,user:string,pass:string,charset:string} $cfg
     */
    public static function connection(array $cfg): PDO
    {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $cfg['host'],
                $cfg['port'],
                $cfg['name'],
                $cfg['charset']
            );

            self::$instance = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }

        return self::$instance;
    }
}
