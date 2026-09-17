<?php

declare(strict_types=1);

/**
 * Front controller — every request enters here.
 */

define('BASE_PATH', dirname(__DIR__));

/* --- Load .env (simple parser; real env vars win) — shared with the CLI
       scripts in scripts/, which need the same environment this request has. --- */
require BASE_PATH . '/config/env.php';

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

/* --- One configured timezone drives BOTH PHP and MySQL (see Database::connection())
 * so date/time comparisons never straddle a clock skew between the two. Must run
 * before any date/session use. --- */
date_default_timezone_set($config['app']['timezone']);

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
use App\Controllers\ArchiveController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\AvatarController;
use App\Controllers\DocumentController;
use App\Controllers\DocumentTypeController;
use App\Controllers\FacultyController;
use App\Controllers\HomeController;
use App\Controllers\NotificationController;
use App\Controllers\PeriodController;
use App\Controllers\ProfileController;
use App\Controllers\RequirementController;
use App\Controllers\ReviewerController;
use App\Controllers\SearchController;
use App\Controllers\SubmissionController;
use App\Controllers\UserController;
use App\Core\Auth;
use App\Core\Router;

/* --- Baseline security headers (mirrored in public/.htaccess for Apache's
 * own static responses; these cover every PHP-generated response). --- */
// PHP advertises its exact version in X-Powered-By unless told not to, which
// hands an attacker the CVE list to start from before they have probed
// anything. expose_php=Off in php.ini is the other half of this; the header is
// removed here too so the posture does not depend on a server the deployment
// may not control.
header_remove('X-Powered-By');

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
$router->get('/faculty/dashboard', [FacultyController::class, 'dashboard']);

$router->get('/admin/document-types', [DocumentTypeController::class, 'index']);
$router->get('/admin/document-types/new', [DocumentTypeController::class, 'create']);
$router->post('/admin/document-types', [DocumentTypeController::class, 'store']);
$router->get('/admin/document-types/{id}/edit', [DocumentTypeController::class, 'edit']);
$router->post('/admin/document-types/{id}', [DocumentTypeController::class, 'update']);
$router->post('/admin/document-types/{id}/deactivate', [DocumentTypeController::class, 'deactivate']);
$router->post('/admin/document-types/{id}/activate', [DocumentTypeController::class, 'activate']);

$router->get('/admin/users', [UserController::class, 'index']);
$router->get('/admin/users/new', [UserController::class, 'create']);
$router->post('/admin/users', [UserController::class, 'store']);
$router->get('/admin/users/{id}/edit', [UserController::class, 'edit']);
$router->post('/admin/users/{id}', [UserController::class, 'update']);
$router->post('/admin/users/{id}/deactivate', [UserController::class, 'deactivate']);
$router->post('/admin/users/{id}/activate', [UserController::class, 'activate']);

$router->get('/admin/periods', [PeriodController::class, 'index']);
$router->get('/admin/periods/new', [PeriodController::class, 'create']);
$router->post('/admin/periods', [PeriodController::class, 'store']);
$router->get('/admin/periods/{id}/edit', [PeriodController::class, 'edit']);
$router->post('/admin/periods/{id}', [PeriodController::class, 'update']);
$router->post('/admin/periods/{id}/activate', [PeriodController::class, 'activate']);
$router->post('/admin/periods/{id}/deactivate', [PeriodController::class, 'deactivate']);

$router->get('/admin/requirements', [RequirementController::class, 'index']);
$router->get('/admin/requirements/new', [RequirementController::class, 'create']);
$router->post('/admin/requirements', [RequirementController::class, 'store']);

$router->get('/admin/monitoring', [AdminController::class, 'monitoring']);
$router->get('/admin/audit-log', [AdminController::class, 'auditLog']);
$router->get('/admin/reports', [AdminController::class, 'reports']);
// Extension-less on purpose: PHP's built-in dev server (php -S) serves any URI
// that "specifies a file" straight from disk and only falls back to index.php
// for extension-less paths, so /admin/reports/export.csv would 404 before ever
// reaching the app. The downloaded filename comes from Content-Disposition.
$router->get('/admin/reports/export', [AdminController::class, 'exportCsv']);

$router->get('/faculty/requirements', [FacultyController::class, 'requirements']);
$router->post('/faculty/submissions/{id}/upload', [FacultyController::class, 'upload']);

$router->get('/reviewer/queue', [ReviewerController::class, 'queue']);
$router->get('/reviewer/submissions/{id}/review', [ReviewerController::class, 'review']);
$router->post('/reviewer/submissions/{id}/review', [ReviewerController::class, 'decide']);
$router->get('/reviewer/compliance', [ReviewerController::class, 'compliance']);
// FR-38: one screen per submission status for the active period, behind the
// sidebar's Review sub-navigation. Extension-less like every other route, and
// {status} is resolved against the submissions.status enum in the controller —
// an unknown value is a 404, never a string that reaches SQL.
$router->get('/reviewer/status/{status}', [ReviewerController::class, 'byStatus']);

// FR-41: the in-app viewer screen. Registered before the two routes that
// serve raw bytes, which it links to.
$router->get('/documents/{id}', [DocumentController::class, 'show']);
$router->get('/documents/{id}/download', [DocumentController::class, 'download']);
// FR-41: the same bytes served inline for the in-app viewer. PDFs only —
// see DocumentController::view() for why, and for the framing headers it
// relaxes on this response alone.
$router->get('/documents/{id}/view', [DocumentController::class, 'preview']);
// Images embedded in a rendered .docx. The second segment is a relationship
// id declared by the document itself, not a path — see DocumentController::media().
$router->get('/documents/{id}/media/{rid}', [DocumentController::class, 'media']);
$router->get('/submissions/{id}', [SubmissionController::class, 'show']);

// Any authenticated role may browse the archive; what they SEE is scoped by
// role inside the controller (Faculty see only their own history).
$router->get('/archive', [ArchiveController::class, 'index']);
$router->get('/archive/{id}', [ArchiveController::class, 'show']);

$router->get('/profile', [ProfileController::class, 'show']);
$router->post('/profile', [ProfileController::class, 'update']);
$router->post('/profile/password', [ProfileController::class, 'changePassword']);
$router->post('/profile/photo', [ProfileController::class, 'uploadPhoto']);
$router->post('/profile/photo/remove', [ProfileController::class, 'removePhoto']);

// Extension-less on purpose (FR-40): the dev server serves any URI with a file
// extension straight from disk, so /avatars/3.png would never reach the router.
// Profile photos live outside the web root and are only ever served from here.
$router->get('/avatars/{id}', [AvatarController::class, 'show']);

// FR-37: role-aware search. Any signed-in role may reach it; what each one
// searches is decided inside the controller and bound into the SQL, so there
// is no scope parameter here for a crafted query string to aim at. GET and
// read-only, so no CSRF — same as the other filter/search forms.
$router->get('/search', [SearchController::class, 'index']);

$router->get('/notifications', [NotificationController::class, 'index']);
$router->post('/notifications/{id}/read', [NotificationController::class, 'markRead']);
$router->post('/notifications/read-all', [NotificationController::class, 'markAllRead']);

$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $uri);
