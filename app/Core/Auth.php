<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\User;
use Throwable;

/**
 * Session-backed authentication. The session itself is started once, centrally,
 * via Auth::boot() from the front controller; everything else reads/writes the
 * minimal identity kept in $_SESSION.
 */
final class Auth
{
    private const SESSION_KEY = 'auth_user';

    /** Idle sessions are logged out after this many seconds of inactivity. */
    private const IDLE_TIMEOUT_SECONDS = 1800;

    /**
     * A fixed, valid bcrypt hash with no corresponding real password. Run against
     * password_verify() on the "unknown/inactive email" path in attempt() so it
     * takes comparable time to a real check (mitigates a user-enumeration timing
     * oracle from the "no such user" path otherwise being measurably faster).
     */
    private const DUMMY_HASH = '$2y$10$ffbRz9Yq47sxFLteGk7sKOakkYOdumU2KbuiymxuvzA1V74bskkhS';

    /**
     * Start the PHP session with a hardened, httponly cookie. Safe to call once
     * per request. Also enforces the idle-session timeout.
     */
    public static function boot(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        ini_set('session.use_strict_mode', '1');

        // Secure depends ONLY on the actual transport, never on the env/debug
        // flags: now that production is the default posture (config/config.php),
        // deriving it from $isProduction would set Secure on plain-HTTP
        // localhost and break login entirely. Production-over-HTTPS still gets
        // Secure; the deployment doc (docs/SECURITY.md) already requires HTTPS.
        $secure = !empty($_SERVER['HTTPS']);

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name('ccisdms_session');
        session_start();

        self::enforceIdleTimeout();
    }

    /**
     * Destroy the session once it has been idle past IDLE_TIMEOUT_SECONDS;
     * otherwise stamp the current activity time. A session with no prior
     * activity recorded (brand-new / anonymous) is left alone.
     */
    private static function enforceIdleTimeout(): void
    {
        $lastActivity = $_SESSION['last_activity'] ?? null;

        if ($lastActivity !== null && (time() - (int) $lastActivity) > self::IDLE_TIMEOUT_SECONDS) {
            self::logout();
            return;
        }

        $_SESSION['last_activity'] = time();
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
        if ($user === null) {
            // No such active user — still run password_verify so this path takes
            // comparable time to a real check (see DUMMY_HASH doc comment).
            password_verify($password, self::DUMMY_HASH);
            return false;
        }

        if (!password_verify($password, $user['password_hash'])) {
            return false;
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT)) {
            try {
                User::updatePasswordHash((int) $user['user_id'], password_hash($password, PASSWORD_BCRYPT));
            } catch (Throwable $e) {
                // Best-effort: a failed rehash must not block a valid login.
                error_log('[CCIS-DMS] password rehash failed for user ' . $user['user_id'] . ': ' . $e->getMessage());
            }
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
     * Update the signed-in user's role in the session, e.g. after Guard reloads
     * the current DB row and finds the role has changed since login.
     */
    public static function refreshRole(string $roleName): void
    {
        if (isset($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY]['role_name'] = $roleName;
        }
    }

    /**
     * Keep the session's copy of the profile photo in step with the database
     * (FR-40).
     *
     * Guard calls this on every authenticated request from the row it already
     * re-reads, so the sidebar avatar costs no extra query and cannot go stale
     * — a photo uploaded or removed in another tab shows up on the next page.
     */
    public static function refreshAvatar(?string $avatarPath): void
    {
        if (isset($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY]['avatar_path'] = $avatarPath;
        }
    }

    /**
     * Update the signed-in user's name and email in the session after they
     * edit their own profile (FR-5), so the header greeting and every
     * `Auth::user()` read reflect the change immediately rather than staying
     * stale until the next login.
     *
     * Role is deliberately not a parameter — it is not self-editable, and
     * refreshRole() remains the only way it changes.
     */
    public static function refreshIdentity(string $firstName, string $lastName, string $email): void
    {
        if (!isset($_SESSION[self::SESSION_KEY])) {
            return;
        }

        $_SESSION[self::SESSION_KEY]['first_name'] = $firstName;
        $_SESSION[self::SESSION_KEY]['last_name'] = $lastName;
        $_SESSION[self::SESSION_KEY]['email'] = $email;
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
