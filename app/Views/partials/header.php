<?php
/**
 * Shared page header: app name + (when signed in) the current user's name,
 * role, and a logout link. Included by every view via require, so it reads
 * whatever local variables the calling view already has in scope.
 *
 * @var string $appName
 */
$authUser = \App\Core\Auth::user();
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
                    <span class="who">
                        <?= htmlspecialchars($authUser['first_name'] . ' ' . $authUser['last_name']) ?>
                        <span class="role-pill"><?= htmlspecialchars($authUser['role_name']) ?></span>
                    </span>
                    <form method="post" action="<?= url('/logout') ?>" class="logout-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(\App\Core\Csrf::token()) ?>">
                        <button type="submit" class="logout-btn">Log out</button>
                    </form>
                </nav>
            <?php endif; ?>
        </div>
    </header>
    <?php if (\App\Core\Auth::hasRole('Administrator')): ?>
        <nav class="subnav">
            <div class="subnav-inner">
                <a href="<?= url('/admin/dashboard') ?>">Dashboard</a>
                <a href="<?= url('/admin/document-types') ?>">Document Types</a>
                <a href="<?= url('/admin/requirements') ?>">Requirements</a>
            </div>
        </nav>
    <?php endif; ?>
    <?php if (\App\Core\Auth::hasRole('Faculty')): ?>
        <nav class="subnav">
            <div class="subnav-inner">
                <a href="<?= url('/faculty/dashboard') ?>">Dashboard</a>
                <a href="<?= url('/faculty/requirements') ?>">My Requirements</a>
            </div>
        </nav>
    <?php endif; ?>
    <main class="wrap">
