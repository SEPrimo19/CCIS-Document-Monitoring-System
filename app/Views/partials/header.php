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
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <header class="topbar">
        <div class="topbar-inner">
            <a class="brand" href="/dashboard">
                <span class="badge">CCIS-DMS</span>
                <span class="brand-name"><?= htmlspecialchars($appName) ?></span>
            </a>
            <?php if ($authUser !== null): ?>
                <nav class="topbar-nav">
                    <span class="who">
                        <?= htmlspecialchars($authUser['first_name'] . ' ' . $authUser['last_name']) ?>
                        <span class="role-pill"><?= htmlspecialchars($authUser['role_name']) ?></span>
                    </span>
                    <a class="logout-link" href="/logout">Log out</a>
                </nav>
            <?php endif; ?>
        </div>
    </header>
    <main class="wrap">
