<?php
/**
 * Shared page chrome: the top bar and sidebar navigation (when signed in) and
 * the opening tags every view closes again in footer.php. Included by every
 * view via require, so it reads whatever local variables the calling view
 * already has in scope.
 *
 * Navigation lives in a left sidebar rather than across the top: the
 * Secretary's menu is eleven items, which wrapped onto two cramped rows as a
 * horizontal bar, and a vertical list has room for the full labels plus the
 * grouping that explains them. The top bar beside it carries only the two
 * controls that must be reachable from every screen without opening a menu:
 * the unread-notification indicator (FR-23) and logout (FR-4).
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

// FR-23: the bell is an icon, so the count has to reach a screen reader through
// the accessible name — a bare icon plus a numeric badge announces neither.
$notifLabel = $unreadNotifCount > 0
    ? sprintf('Notifications (%d unread)', $unreadNotifCount)
    : 'Notifications (no unread)';

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

/**
 * The sidebar's Notifications entry, shared by both role menus.
 *
 * Deliberately NOT folded into $navLink: it carries the unread count, and the
 * highlight has to survive /notifications as well as any child path.
 */
$notifNavLink = static function () use ($currentPath, $unreadNotifCount): void {
    $isCurrent = str_starts_with($currentPath, rtrim(url('/notifications'), '/'));
    ?>
    <a class="sidenav-link notif-link<?= $isCurrent ? ' is-current' : '' ?>" href="<?= url('/notifications') ?>">
        Notifications
        <?php if ($unreadNotifCount > 0): ?>
            <span class="notif-badge"><?= htmlspecialchars((string) $unreadNotifCount) ?></span>
        <?php endif; ?>
    </a>
    <?php
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

    <?php // Persistent top bar, at every width. It sits before the sidebar in ?>
    <?php // the DOM so that on a narrow screen the drawer opens directly ?>
    <?php // beneath the button that toggles it, and outside .app-main so the ?>
    <?php // two are siblings the grid can place into named areas. The "Menu" ?>
    <?php // button and the brand inside it are the narrow-screen half and are ?>
    <?php // hidden from the sidebar breakpoint up (where the sidebar itself ?>
    <?php // shows the brand); the notification and logout controls are not. ?>
    <header class="topbar">
        <button type="button" class="nav-toggle" aria-controls="sidebar" aria-expanded="false">
            <span class="nav-toggle-bars" aria-hidden="true"></span>
            <span>Menu</span>
        </button>
        <a class="topbar-brand" href="<?= url('/dashboard') ?>">
            <?php if ($brandLogo !== null): ?>
                <?php // The logo REPLACES the wordmark rather than sitting beside ?>
                <?php // it, so it needs the alt text the badge used to supply. ?>
                <img class="topbar-logo" src="<?= htmlspecialchars($brandLogo) ?>" alt="CCIS-DMS" width="28" height="28">
            <?php else: ?>
                <span class="badge">CCIS-DMS</span>
            <?php endif; ?>
        </a>

        <div class="topbar-actions">
            <?php // FR-23: the at-a-glance unread indicator. This duplicates the ?>
            <?php // sidebar's Notifications entry on purpose — that entry is ?>
            <?php // navigation (one item among the menu's other screens, hidden ?>
            <?php // inside the drawer on a phone), whereas this is a status ?>
            <?php // indicator that has to be visible without opening anything. ?>
            <?php // Logout is NOT duplicated for the same reason: it is one ?>
            <?php // action with one home, so a second copy in .sidebar-foot ?>
            <?php // would just be two buttons doing the same thing. ?>
            <a class="topbar-action notif-bell" href="<?= url('/notifications') ?>" aria-label="<?= htmlspecialchars($notifLabel) ?>">
                <?php // Inline SVG: there is no icon library and the CSP forbids ?>
                <?php // off-origin assets. Stroke/size live in CSS (.topbar-icon) ?>
                <?php // so the icon inherits currentColor in both themes. ?>
                <svg class="topbar-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                </svg>
                <?php if ($unreadNotifCount > 0): ?>
                    <?php // aria-hidden: the count is already in the aria-label ?>
                    <?php // above, and announcing it twice reads as noise. ?>
                    <span class="topbar-badge" aria-hidden="true"><?= htmlspecialchars((string) $unreadNotifCount) ?></span>
                <?php endif; ?>
            </a>

            <?php // FR-4: logout stays a POST + CSRF form, never a link — a GET ?>
            <?php // logout is trivially forgeable from any third-party page. ?>
            <form method="post" action="<?= url('/logout') ?>" class="logout-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(\App\Core\Csrf::token()) ?>">
                <button type="submit" class="topbar-action">
                    <svg class="topbar-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                        <path d="M16 17l5-5-5-5"/>
                        <path d="M21 12H9"/>
                    </svg>
                    <?php // Clipped rather than dropped below 600px, so the button ?>
                    <?php // keeps its accessible name when only the icon shows. ?>
                    <span class="topbar-action-text">Log out</span>
                </button>
            </form>
        </div>
    </header>

    <aside class="sidebar" id="sidebar">
        <a class="brand" href="<?= url('/dashboard') ?>">
            <?php if ($brandLogo !== null): ?>
                <?php // alt="" on purpose: .brand-name beside it already names the ?>
                <?php // system, so announcing the logo too just repeats it. ?>
                <img class="brand-logo" src="<?= htmlspecialchars($brandLogo) ?>" alt="" width="40" height="40">
            <?php endif; ?>
            <span class="brand-text">
                <?php // The supplied logo replaces the "CCIS-DMS" wordmark; the ?>
                <?php // full system name stays either way. ?>
                <?php if ($brandLogo === null): ?>
                    <span class="badge">CCIS-DMS</span>
                <?php endif; ?>
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
                <?php $notifNavLink(); ?>

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
            <?php // Headed in the same .sidenav-heading pattern as the Secretary's ?>
            <?php // menu, so a user who has seen both reads one navigation, not ?>
            <?php // two. Split by what the item is FOR rather than by how many ?>
            <?php // there are: Overview is the two at-a-glance screens, Documents ?>
            <?php // the two that hold actual files — this period's in "My ?>
            <?php // Requirements" (FR-6), earlier periods' in "Archive". ?>
            <nav class="sidenav" aria-label="Main navigation">
                <p class="sidenav-heading">Overview</p>
                <?php $navLink('/faculty/dashboard', 'Dashboard'); ?>
                <?php $notifNavLink(); ?>

                <p class="sidenav-heading">Documents</p>
                <?php $navLink('/faculty/requirements', 'My Requirements'); ?>
                <?php $navLink('/archive', 'Archive'); ?>
            </nav>
        <?php endif; ?>

        <div class="sidebar-foot">
            <?php // Avatar in place of the old role pill. The initials are the
                  // fallback until an uploaded profile image exists: .avatar is
                  // the frame, and its single child is what swaps — drop an
                  // <img class="avatar-img"> in beside .avatar-initials and the
                  // surrounding markup is unchanged. The role moved to a line of
                  // its own under the name; it is what the whole menu above is
                  // keyed to, so it must not simply vanish with the pill. ?>
            <a class="who" href="<?= url('/profile') ?>">
                <span class="avatar" aria-hidden="true">
                    <span class="avatar-initials"><?= htmlspecialchars(user_initials($authUser['first_name'], $authUser['last_name'])) ?></span>
                </span>
                <span class="who-text">
                    <span class="who-name"><?= htmlspecialchars($authUser['first_name'] . ' ' . $authUser['last_name']) ?></span>
                    <span class="who-role">Signed in as <?= htmlspecialchars($authUser['role_name']) ?></span>
                </span>
            </a>
        </div>
    </aside>
<?php endif; ?>

    <div class="app-main">
        <?php if ($authUser === null): ?>
            <?php // Signed out (the login screen): no navigation to show, but the ?>
            <?php // logo still identifies whose system this is — and replaces the ?>
            <?php // wordmark when one has been supplied. ?>
            <div class="auth-brandbar">
                <?php if ($brandLogo !== null): ?>
                    <img class="auth-logo" src="<?= htmlspecialchars($brandLogo) ?>" alt="CCIS-DMS" width="56" height="56">
                <?php else: ?>
                    <span class="badge">CCIS-DMS</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <main class="wrap" id="main">
