<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
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
        header('Location: ' . (Auth::check() ? '/dashboard' : '/login'));
        exit;
    }

    public function health(): void
    {
        $config = $this->config();

        $dbStatus = 'error';
        $dbDetail = '';

        try {
            $pdo = Database::connection($config['db']);
            $version = $pdo->query('SELECT VERSION()')->fetchColumn();
            $dbStatus = 'connected';
            $dbDetail = "MySQL/MariaDB {$version} · database '{$config['db']['name']}'";
        } catch (Throwable $e) {
            $dbDetail = $e->getMessage();
        }

        $this->view('home', [
            'appName'  => $config['app']['name'],
            'phpVer'   => PHP_VERSION,
            'dbStatus' => $dbStatus,
            'dbDetail' => $dbDetail,
        ]);
    }
}
