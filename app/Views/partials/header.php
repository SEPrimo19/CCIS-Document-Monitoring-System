<?php
/**
 * Shared page header: app name + (when signed in) the current user's name,
 * role, and a logout link. Included by every view via require, so it reads
 * whatever local variables the calling view already has in scope.
 *
 * @var string $appName
 */
$authUser = \App\Core\Auth::user();
// Best-effort: errors/500.php includes this partial, so if the exception that
// triggered the error handler in the first place WAS a DB outage, this query
// would throw again inside the handler and turn a 500 into a blank page.
try {
    $unreadNotifCount = $authUser !== null
        ? \App\Models\Notification::unreadCount((int) $authUser['user_id'])
        : 0;
} catch (\Throwable) {
    $unreadNotifCount = 0;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($appName) ?></title>
    <link rel="stylesheet" href="<?= asset('css/style.css') ?>">
</head>
<body>
    <header class="topbar">
        <div class="topbar-inner">
            <a class="brand" href="<?= url('/dashboard') ?>">
                <span class="badge">CCIS-DMS</span>
                <span class="brand-name"><?= htmlspecialchars($appName) ?></span>
            </a>
            <?php if ($authUser !== null): ?>
                <nav class="topbar-nav">
                    <a class="notif-link" href="<?= url('/notifications') ?>">
                        Notifications
                        <?php if ($unreadNotifCount > 0): ?>
                            <span class="notif-badge"><?= htmlspecialchars((string) $unreadNotifCount) ?></span>
                        <?php endif; ?>
                    </a>
                    <a class="who" href="<?= url('/profile') ?>">
                        <?= htmlspecialchars($authUser['first_name'] . ' ' . $authUser['last_name']) ?>
                        <span class="role-pill"><?= htmlspecialchars($authUser['role_name']) ?></span>
                    </a>
                    <form method="post" action="<?= url('/logout') ?>" class="logout-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(\App\Core\Csrf::token()) ?>">
                        <button type="submit" class="logout-btn">Log out</button>
                    </form>
                </nav>
            <?php endif; ?>
        </div>
    </header>
    <?php // One navigation per role. The Secretary's covers both halves of the ?>
    <?php // job — configuring the system and verifying submissions — grouped in ?>
    <?php // the order the work actually happens: set up, then review, then report. ?>
    <?php if (\App\Core\Auth::hasRole('Secretary')): ?>
        <nav class="subnav">
            <div class="subnav-inner">
                <a href="<?= url('/admin/dashboard') ?>">Dashboard</a>
                <a href="<?= url('/reviewer/queue') ?>">Review Queue</a>
                <a href="<?= url('/admin/monitoring') ?>">Monitoring</a>
                <a href="<?= url('/reviewer/compliance') ?>">Faculty Compliance</a>
                <a href="<?= url('/admin/requirements') ?>">Requirements</a>
                <a href="<?= url('/admin/document-types') ?>">Document Types</a>
                <a href="<?= url('/admin/periods') ?>">Periods</a>
                <a href="<?= url('/admin/users') ?>">Users</a>
                <a href="<?= url('/admin/reports') ?>">Reports</a>
                <a href="<?= url('/admin/audit-log') ?>">Audit Log</a>
                <a href="<?= url('/archive') ?>">Archive</a>
            </div>
        </nav>
    <?php endif; ?>
    <?php if (\App\Core\Auth::hasRole('Faculty')): ?>
        <nav class="subnav">
            <div class="subnav-inner">
                <a href="<?= url('/faculty/dashboard') ?>">Dashboard</a>
                <a href="<?= url('/faculty/requirements') ?>">My Requirements</a>
                <a href="<?= url('/archive') ?>">Archive</a>
            </div>
        </nav>
    <?php endif; ?>
    <main class="wrap">
