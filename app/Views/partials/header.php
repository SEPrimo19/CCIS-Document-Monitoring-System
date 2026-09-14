<?php
/**
 * Shared page chrome: the sidebar navigation (when signed in) and the opening
 * tags every view closes again in footer.php. Included by every view via
 * require, so it reads whatever local variables the calling view already has
 * in scope.
 *
 * Navigation lives in a left sidebar rather than across the top: the
 * Secretary's menu is eleven items, which wrapped onto two cramped rows as a
 * horizontal bar, and a vertical list has room for the full labels plus the
 * grouping that explains them.
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

$brandLogo = brand_logo();

// Request path, for marking the active nav item. Compared against the values
// url() produces (which carry BASE_URL), so the highlight survives being
// served from a subfolder as well as from the web root.
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

/**
 * One sidebar link, marking itself current from the URL so no view has to
 * remember to pass a "which page am I" flag. A child path counts as current
 * too — /admin/requirements/new keeps "Requirements" highlighted.
 */
$navLink = static function (string $path, string $label) use ($currentPath): void {
    $href = url($path);
    $base = rtrim($href, '/');
    $isCurrent = $currentPath === $href
        || $currentPath === $base
        || str_starts_with($currentPath, $base . '/');

    printf(
        '<a class="sidenav-link%s" href="%s"%s>%s</a>',
        $isCurrent ? ' is-current' : '',
        htmlspecialchars($href),
        $isCurrent ? ' aria-current="page"' : '',
        htmlspecialchars($label)
    );
};
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
<div class="app-shell<?= $authUser !== null ? ' app-shell-auth' : '' ?>">

<?php if ($authUser !== null): ?>
    <?php // Skip link: the sidebar is eleven links the keyboard would otherwise ?>
    <?php // have to walk through on every single page load. ?>
    <a class="skip-link" href="#main">Skip to main content</a>

    <?php // Narrow screens only — CSS hides it from the sidebar breakpoint up. ?>
    <?php // It sits before the sidebar so the drawer opens directly beneath the ?>
    <?php // button that toggles it, and outside .app-main so the two are ?>
    <?php // siblings the flex column can order naturally. Without JS it never ?>
    <?php // appears and the sidebar simply stacks above the content. ?>
    <header class="topbar">
        <button type="button" class="nav-toggle" aria-controls="sidebar" aria-expanded="false">
            <span class="nav-toggle-bars" aria-hidden="true"></span>
            <span>Menu</span>
        </button>
        <a class="topbar-brand" href="<?= url('/dashboard') ?>">
            <?php if ($brandLogo !== null): ?>
                <img class="topbar-logo" src="<?= htmlspecialchars($brandLogo) ?>" alt="" width="28" height="28">
            <?php endif; ?>
            <span class="badge">CCIS-DMS</span>
        </a>
    </header>

    <aside class="sidebar" id="sidebar">
        <a class="brand" href="<?= url('/dashboard') ?>">
            <?php if ($brandLogo !== null): ?>
                <?php // alt="" on purpose: the wordmark beside it already names ?>
                <?php // the system, so announcing the logo too just repeats it. ?>
                <img class="brand-logo" src="<?= htmlspecialchars($brandLogo) ?>" alt="" width="40" height="40">
            <?php endif; ?>
            <span class="brand-text">
                <span class="badge">CCIS-DMS</span>
                <span class="brand-name"><?= htmlspecialchars($appName) ?></span>
            </span>
        </a>

        <?php // One navigation per role. The Secretary's covers both halves of ?>
        <?php // the job — configuring the system and verifying submissions — ?>
        <?php // grouped in the order the work actually happens: review what is ?>
        <?php // waiting, set the rules, then look back over the records. ?>
        <?php if (\App\Core\Auth::hasRole('Secretary')): ?>
            <nav class="sidenav" aria-label="Main navigation">
                <p class="sidenav-heading">Overview</p>
                <?php $navLink('/admin/dashboard', 'Dashboard'); ?>
                <a class="sidenav-link notif-link<?= str_starts_with($currentPath, rtrim(url('/notifications'), '/')) ? ' is-current' : '' ?>" href="<?= url('/notifications') ?>">
                    Notifications
                    <?php if ($unreadNotifCount > 0): ?>
                        <span class="notif-badge"><?= htmlspecialchars((string) $unreadNotifCount) ?></span>
                    <?php endif; ?>
                </a>

                <p class="sidenav-heading">Review</p>
                <?php $navLink('/reviewer/queue', 'Review Queue'); ?>
                <?php $navLink('/reviewer/compliance', 'Faculty Compliance'); ?>
                <?php $navLink('/admin/monitoring', 'Monitoring'); ?>

                <p class="sidenav-heading">Configure</p>
                <?php $navLink('/admin/requirements', 'Requirements'); ?>
                <?php $navLink('/admin/document-types', 'Document Types'); ?>
                <?php $navLink('/admin/periods', 'Periods'); ?>
                <?php $navLink('/admin/users', 'Users'); ?>

                <p class="sidenav-heading">Records</p>
                <?php $navLink('/admin/reports', 'Reports'); ?>
                <?php $navLink('/admin/audit-log', 'Audit Log'); ?>
                <?php $navLink('/archive', 'Archive'); ?>
            </nav>
        <?php endif; ?>

        <?php if (\App\Core\Auth::hasRole('Faculty')): ?>
            <?php // Three items and a notification link — too few to need the ?>
            <?php // Secretary's group headings. ?>
            <nav class="sidenav" aria-label="Main navigation">
                <?php $navLink('/faculty/dashboard', 'Dashboard'); ?>
                <?php $navLink('/faculty/requirements', 'My Requirements'); ?>
                <a class="sidenav-link notif-link<?= str_starts_with($currentPath, rtrim(url('/notifications'), '/')) ? ' is-current' : '' ?>" href="<?= url('/notifications') ?>">
                    Notifications
                    <?php if ($unreadNotifCount > 0): ?>
                        <span class="notif-badge"><?= htmlspecialchars((string) $unreadNotifCount) ?></span>
                    <?php endif; ?>
                </a>
                <?php $navLink('/archive', 'Archive'); ?>
            </nav>
        <?php endif; ?>

        <div class="sidebar-foot">
            <a class="who" href="<?= url('/profile') ?>">
                <span class="who-name"><?= htmlspecialchars($authUser['first_name'] . ' ' . $authUser['last_name']) ?></span>
                <span class="role-pill"><?= htmlspecialchars($authUser['role_name']) ?></span>
            </a>
            <form method="post" action="<?= url('/logout') ?>" class="logout-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(\App\Core\Csrf::token()) ?>">
                <button type="submit" class="logout-btn">Log out</button>
            </form>
        </div>
    </aside>
<?php endif; ?>

    <div class="app-main">
        <?php if ($authUser === null): ?>
            <?php // Signed out (the login screen): no navigation to show, but the ?>
            <?php // logo still identifies whose system this is. ?>
            <div class="auth-brandbar">
                <?php if ($brandLogo !== null): ?>
                    <img class="auth-logo" src="<?= htmlspecialchars($brandLogo) ?>" alt="" width="56" height="56">
                <?php endif; ?>
                <span class="badge">CCIS-DMS</span>
            </div>
        <?php endif; ?>

        <main class="wrap" id="main">
