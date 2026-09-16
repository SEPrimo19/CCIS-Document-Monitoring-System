<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\User;

/**
 * Route guards for authentication & role-based access control (RBAC). Every
 * protected controller action calls these before doing any work, so authorization
 * is always enforced on the server — never merely hidden in the UI (FR-3).
 */
final class Guard
{
    /**
     * Request-scoped cache of the signed-in user's current DB row, so
     * requireRole()'s internal requireAuth() call doesn't re-query.
     *
     * @var array{user_id:int,first_name:string,last_name:string,email:string,status:string,role_name:string}|null
     */
    private static ?array $freshUser = null;
    private static bool $freshUserLoaded = false;

    /**
     * Require a signed-in user whose account is still active; otherwise redirect
     * to the login screen. Re-validates against the database every request (not
     * just the login-time session snapshot), so deactivating an account takes
     * effect immediately rather than waiting for the user to log out.
     */
    public static function requireAuth(): void
    {
        if (!Auth::check()) {
            self::redirectToLogin();
        }

        $fresh = self::freshUser();
        if ($fresh === null || $fresh['status'] !== 'active') {
            Auth::logout();
            self::redirectToLogin();
        }
    }

    /**
     * Require a signed-in, active user whose CURRENT (freshly loaded) role is one
     * of the given roles; otherwise render a 403 page. Implies requireAuth().
     */
    public static function requireRole(string ...$roles): void
    {
        self::requireAuth();

        $role = self::$freshUser['role_name'] ?? null;
        if ($role === null || !in_array($role, $roles, true)) {
            self::forbidden();
        }
    }

    /**
     * Load and cache the signed-in user's current DB row (one query per request),
     * refreshing the session's role so a role change takes effect immediately.
     */
    private static function freshUser(): ?array
    {
        if (self::$freshUserLoaded) {
            return self::$freshUser;
        }
        self::$freshUserLoaded = true;

        $sessionUser = Auth::user();
        if ($sessionUser === null) {
            return self::$freshUser = null;
        }

        $fresh = User::findById((int) $sessionUser['user_id']);
        self::$freshUser = $fresh;

        if ($fresh !== null && $fresh['status'] === 'active') {
            Auth::refreshRole($fresh['role_name']);
            Auth::refreshAvatar($fresh['avatar_path'] ?? null);
        }

        return $fresh;
    }

    private static function redirectToLogin(): void
    {
        header('Location: ' . url('/login'));
        exit;
    }

    private static function forbidden(): void
    {
        http_response_code(403);

        $config = require dirname(__DIR__, 2) . '/config/config.php';

        $appName = $config['app']['name'];
        require dirname(__DIR__) . '/Views/errors/403.php';
        exit;
    }
}
