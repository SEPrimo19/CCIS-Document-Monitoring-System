<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use Throwable;

/**
 * Phase 0 landing / health check. Confirms the app boots and can reach MySQL.
 */
final class HomeController extends Controller
{
    public function index(): void
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
