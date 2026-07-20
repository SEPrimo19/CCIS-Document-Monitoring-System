<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\User;

/**
 * Session-backed authentication. The session itself is started once, centrally,
 * via Auth::boot() from the front controller; everything else reads/writes the
 * minimal identity kept in $_SESSION.
 */
final class Auth
{
    private const SESSION_KEY = 'auth_user';

    /**
     * Start the PHP session with an httponly cookie. Safe to call once per request.
     */
    public static function boot(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name('ccisdms_session');
        session_start();
    }

    /**
     * Validate credentials against an active account. On success, regenerates the
     * session id, stores the signed-in identity, and records the login timestamp.
     * Returns false for any failure reason (unknown email, wrong password, inactive
     * account) without distinguishing which — callers must show one generic message.
     */
    public static function attempt(string $email, string $password): bool
    {
        $email = self::normalizeEmail($email);
        if ($email === '' || $password === '') {
            return false;
        }

        $user = User::findActiveByEmail($email);
        if ($user === null || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);

        $_SESSION[self::SESSION_KEY] = [
            'user_id'    => (int) $user['user_id'],
            'first_name' => $user['first_name'],
            'last_name'  => $user['last_name'],
            'email'      => $user['email'],
            'role_name'  => $user['role_name'],
        ];

        User::touchLastLogin((int) $user['user_id']);

        return true;
    }

    /**
     * The signed-in user's identity, or null if no one is signed in.
     *
     * @return array{user_id:int,first_name:string,last_name:string,email:string,role_name:string}|null
     */
    public static function user(): ?array
    {
        return $_SESSION[self::SESSION_KEY] ?? null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    /**
     * True if the signed-in user's role is one of the given roles.
     */
    public static function hasRole(string ...$roles): bool
    {
        $current = self::user()['role_name'] ?? null;

        return $current !== null && in_array($current, $roles, true);
    }

    /**
     * End the session: clear identity data, drop the session cookie, and destroy
     * the server-side session record.
     */
    public static function logout(): void
    {
        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
            session_destroy();
        }
    }

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }
}
