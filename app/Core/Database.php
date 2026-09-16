<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Thin PDO factory. Keeps a single connection per request.
 */
final class Database
{
    /**
     * Escape character used by every LIKE pattern this class builds. NOT the
     * backslash MySQL defaults to: the backslash is also the escape character
     * of MySQL's own string literals, so how many of them an ESCAPE clause
     * needs depends on sql_mode (NO_BACKSLASH_ESCAPES). '!' has no meaning
     * anywhere in SQL and so behaves identically under every sql_mode.
     */
    private const LIKE_ESCAPE = '!';

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

    /**
     * A bound `%term%` LIKE pattern with the pattern metacharacters in the
     * user's term neutralised (FR-37 search).
     *
     * Binding a search term as a parameter stops it changing the SQL, but it
     * does NOT stop it changing the PATTERN: a term of "%" or "_" would still
     * reach LIKE as a wildcard and match every row. Every metacharacter — and
     * the escape character itself, first, or escaping would double-escape —
     * is prefixed here so the term can only ever match itself literally.
     *
     * Pair with `LIKE :param ESCAPE '!'` (see likeEscapeClause()).
     */
    public static function likePattern(string $term): string
    {
        $escaped = str_replace(
            [self::LIKE_ESCAPE, '%', '_'],
            [self::LIKE_ESCAPE . self::LIKE_ESCAPE, self::LIKE_ESCAPE . '%', self::LIKE_ESCAPE . '_'],
            $term
        );

        return '%' . $escaped . '%';
    }

    /**
     * The `ESCAPE '<char>'` fragment that every LIKE built from likePattern()
     * must carry, so the escape character is stated in exactly one place
     * rather than hard-coded into each finder's SQL.
     */
    public static function likeEscapeClause(): string
    {
        return " ESCAPE '" . self::LIKE_ESCAPE . "'";
    }
}
