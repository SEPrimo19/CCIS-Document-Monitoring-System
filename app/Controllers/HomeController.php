<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Guard;
use Throwable;

/**
 * / forwards by auth state (FR-4): signed-in users go to their dashboard,
 * everyone else goes to the login screen. The Phase 0 health check moved to
 * /health so it stays available for diagnostics without being the app's home.
 */
final class HomeController extends Controller
{
    public function index(): void
    {
        header('Location: ' . url(Auth::check() ? '/dashboard' : '/login'));
        exit;
    }

    /**
     * Diagnostics page: DB engine/version, DB name, PHP version. Admin-only —
     * this is infrastructure detail, not something to expose publicly.
     */
    public function health(): void
    {
        Guard::requireRole('Secretary');

        $config = $this->config();
        $isProduction = $config['app']['env'] === 'production' || $config['app']['debug'] === false;

        $dbStatus = 'error';
        $dbDetail = '';

        try {
            $pdo = Database::connection($config['db']);
            $version = $pdo->query('SELECT VERSION()')->fetchColumn();
            $dbStatus = 'connected';
            $dbDetail = "MySQL/MariaDB {$version} · database '{$config['db']['name']}'";
        } catch (Throwable $e) {
            $dbDetail = $isProduction ? 'unavailable' : $e->getMessage();
        }

        $this->view('home', [
            'appName'  => $config['app']['name'],
            'phpVer'   => PHP_VERSION,
            'dbStatus' => $dbStatus,
            'dbDetail' => $dbDetail,
        ]);
    }
}
