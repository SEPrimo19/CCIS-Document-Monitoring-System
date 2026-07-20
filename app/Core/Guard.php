<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Route guards for authentication & role-based access control (RBAC). Every
 * protected controller action calls these before doing any work, so authorization
 * is always enforced on the server — never merely hidden in the UI (FR-3).
 */
final class Guard
{
    /**
     * Require a signed-in user; otherwise redirect to the login screen.
     */
    public static function requireAuth(): void
    {
        if (!Auth::check()) {
            header('Location: /login');
            exit;
        }
    }

    /**
     * Require a signed-in user whose role is one of the given roles; otherwise
     * render a 403 page. Implies requireAuth().
     */
    public static function requireRole(string ...$roles): void
    {
        self::requireAuth();

        if (!Auth::hasRole(...$roles)) {
            self::forbidden();
        }
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
