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
     * @param array{host:string,port:string,name:string,user:string,pass:string,charset:string,timezone_offset:string} $cfg
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
                // Keep the MySQL session clock in sync with PHP's configured
                // timezone (config/config.php) so date/time comparisons never
                // straddle a clock skew between the app server and the DB
                // server. The offset comes from config, never from user input.
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '" . $cfg['timezone_offset'] . "'",
            ]);
        }

        return self::$instance;
    }
}
