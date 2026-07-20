<?php

declare(strict_types=1);

/**
 * Front controller — every request enters here.
 */

define('BASE_PATH', dirname(__DIR__));

/* --- Load .env (simple parser; real env vars win) --- */
$envFile = BASE_PATH . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\"'");
        if (getenv($key) === false) {
            putenv("{$key}={$value}");
        }
    }
}

/* --- PSR-4 autoloader: App\ => app/ --- */
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = BASE_PATH . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\FacultyController;
use App\Controllers\HomeController;
use App\Controllers\ReviewerController;
use App\Core\Auth;
use App\Core\Router;

/* --- Session (httponly cookie), started once, centrally --- */
Auth::boot();

/* --- Resolve request path, tolerant of being served from a subfolder --- */
$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$base = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
if ($base !== '/' && $base !== '' && str_starts_with($uri, $base)) {
    $uri = substr($uri, strlen($base));
}
if ($uri === '' || $uri === false) {
    $uri = '/';
}

/* --- Routes --- */
$router = new Router();
$router->get('/', [HomeController::class, 'index']);
$router->get('/health', [HomeController::class, 'health']);

$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/logout', [AuthController::class, 'logout']);

$router->get('/dashboard', [DashboardController::class, 'index']);
$router->get('/admin/dashboard', [AdminController::class, 'dashboard']);
$router->get('/reviewer/dashboard', [ReviewerController::class, 'dashboard']);
$router->get('/faculty/dashboard', [FacultyController::class, 'dashboard']);

$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $uri);
