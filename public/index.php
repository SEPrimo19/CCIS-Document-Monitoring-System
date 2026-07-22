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

/* --- Global helpers (url(), asset()) — not classes, so require explicitly --- */
require BASE_PATH . '/app/Core/helpers.php';

/* --- Base path for outbound URLs, tolerant of being served from a subfolder.
 * Computed once from the same normalized SCRIPT_NAME directory used below to
 * strip the inbound request path, so both directions agree. --- */
$base = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
define('BASE_URL', $base === '/' ? '' : $base);

/* --- Env / debug detection drives error display and cookie hardening --- */
$config = require BASE_PATH . '/config/config.php';
$isProduction = $config['app']['env'] === 'production' || $config['app']['debug'] === false;

ini_set('log_errors', '1');
ini_set('display_errors', $isProduction ? '0' : '1');

/* --- Global exception handler: never leak stack traces / SQL detail --- */
set_exception_handler(static function (Throwable $e) use ($config, $isProduction): void {
    error_log(sprintf(
        '[CCIS-DMS] Uncaught %s: %s in %s:%d',
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));

    if (!headers_sent()) {
        http_response_code(500);
    }

    $appName = $config['app']['name'];
    $debugMessage = $isProduction ? null : $e->getMessage();

    require BASE_PATH . '/app/Views/errors/500.php';
});

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\DocumentController;
use App\Controllers\DocumentTypeController;
use App\Controllers\FacultyController;
use App\Controllers\HomeController;
use App\Controllers\NotificationController;
use App\Controllers\RequirementController;
use App\Controllers\ReviewerController;
use App\Core\Auth;
use App\Core\Router;

/* --- Baseline security headers (mirrored in public/.htaccess for Apache's
 * own static responses; these cover every PHP-generated response). --- */
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");

/* --- Session (httponly cookie), started once, centrally --- */
Auth::boot();

/* --- Resolve request path, tolerant of being served from a subfolder --- */
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
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
$router->post('/logout', [AuthController::class, 'logout']);

$router->get('/dashboard', [DashboardController::class, 'index']);
$router->get('/admin/dashboard', [AdminController::class, 'dashboard']);
$router->get('/reviewer/dashboard', [ReviewerController::class, 'dashboard']);
$router->get('/faculty/dashboard', [FacultyController::class, 'dashboard']);

$router->get('/admin/document-types', [DocumentTypeController::class, 'index']);
$router->get('/admin/document-types/new', [DocumentTypeController::class, 'create']);
$router->post('/admin/document-types', [DocumentTypeController::class, 'store']);
$router->get('/admin/document-types/{id}/edit', [DocumentTypeController::class, 'edit']);
$router->post('/admin/document-types/{id}', [DocumentTypeController::class, 'update']);
$router->post('/admin/document-types/{id}/deactivate', [DocumentTypeController::class, 'deactivate']);
$router->post('/admin/document-types/{id}/activate', [DocumentTypeController::class, 'activate']);

$router->get('/admin/requirements', [RequirementController::class, 'index']);
$router->get('/admin/requirements/new', [RequirementController::class, 'create']);
$router->post('/admin/requirements', [RequirementController::class, 'store']);

$router->get('/admin/monitoring', [AdminController::class, 'monitoring']);

$router->get('/faculty/requirements', [FacultyController::class, 'requirements']);
$router->post('/faculty/submissions/{id}/upload', [FacultyController::class, 'upload']);

$router->get('/reviewer/queue', [ReviewerController::class, 'queue']);
$router->get('/reviewer/submissions/{id}/review', [ReviewerController::class, 'review']);
$router->post('/reviewer/submissions/{id}/review', [ReviewerController::class, 'decide']);

$router->get('/documents/{id}/download', [DocumentController::class, 'download']);

$router->get('/notifications', [NotificationController::class, 'index']);
$router->post('/notifications/{id}/read', [NotificationController::class, 'markRead']);
$router->post('/notifications/read-all', [NotificationController::class, 'markAllRead']);

$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $uri);
