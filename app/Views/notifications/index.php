<?php
/**
 * @var string $appName
 * @var list<array{notification_id:int,user_id:int,submission_id:?int,type:string,title:string,message:string,is_read:int,created_at:string}> $notifications rows for the ACTIVE tab, already filtered by the controller
 * @var string $filter 'all'|'unread' — which tab is active (FR-22)
 * @var int $totalCount every notification this user has (not just this page)
 * @var int $unreadCount unread across every notification (matches the header badge)
 * @var array{type:string,message:string}|null $flash
 * @var string $csrf
 */
require __DIR__ . '/../partials/header.php';

$isFaculty = \App\Core\Auth::hasRole('Faculty');

/**
 * A severity category per notification so an approval, a returned document,
 * and a deadline reminder are visually distinct instead of all looking the
 * same. Returns [label, css-class]; the text label keeps the cue readable
 * without relying on colour alone.
 */
$categoryFor = static function (array $n): array {
    $title = strtolower($n['title']);
    // Both time-based reminder types — 'deadline' (approaching) and 'pending'
    // (a new assignment or an overdue item) — read as "Reminder", so an
    // actionable heads-up never renders as a plain grey "Update".
    if ($n['type'] === 'deadline' || $n['type'] === 'pending' || str_contains($title, 'deadline')) {
        return ['Reminder', 'notif-cat-reminder'];
    }
    if (str_contains($title, 'approv')) {
        return ['Approved', 'notif-cat-approved'];
    }
    if (str_contains($title, 'return') || str_contains($title, 'revision') || str_contains($title, 'reject')) {
        return ['Action needed', 'notif-cat-action'];
    }
    return ['Update', 'notif-cat-update'];
};
?>
<section class="page-head">
    <div>
        <span class="badge">Notifications</span>
        <h1>Notifications</h1>
        <p class="sub">Updates about your submissions and requirements.</p>
    </div>
    <?php if ($unreadCount > 0): ?>
        <form method="post" action="<?= url('/notifications/read-all') ?>" class="inline-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
            <button type="submit" class="btn-sm btn-secondary">Mark all as read</button>
        </form>
    <?php endif; ?>
</section>

<?php if ($flash !== null): ?>
    <p class="alert <?= $flash['type'] === 'ok' ? 'alert-ok' : 'alert-err' ?>" role="alert">
        <?= htmlspecialchars($flash['message']) ?>
    </p>
<?php endif; ?>

<div class="notif-tabs">
    <a class="notif-tab<?= $filter === 'all' ? ' active' : '' ?>" href="<?= url('/notifications') ?>">All (<?= $totalCount ?>)</a>
    <a class="notif-tab<?= $filter === 'unread' ? ' active' : '' ?>" href="<?= url('/notifications?filter=unread') ?>">Unread (<?= $unreadCount ?>)</a>
</div>

<?php if ($notifications === []): ?>
    <p class="muted-note"><?= $filter === 'unread' ? "You're all caught up — no unread notifications." : 'You have no notifications.' ?></p>
<?php else: ?>
    <ul class="notif-list">
        <?php foreach ($notifications as $notif): ?>
            <?php
            $isUnread = (int) $notif['is_read'] === 0;
            [$catLabel, $catClass] = $categoryFor($notif);
            ?>
            <li class="notif-item<?= $isUnread ? ' unread' : '' ?>">
                <div class="notif-body">
                    <p class="notif-title">
                        <?php if ($isUnread): ?><span class="notif-dot" aria-hidden="true"></span><?php endif; ?>
                        <span class="notif-cat <?= $catClass ?>"><?= htmlspecialchars($catLabel) ?></span>
                        <?= htmlspecialchars($notif['title']) ?>
                    </p>
                    <p class="notif-message"><?= htmlspecialchars($notif['message']) ?></p>
                    <p class="notif-meta">
                        <?= htmlspecialchars(date('M j, Y g:i A', strtotime($notif['created_at']))) ?>
                        <?php if ($isFaculty && $notif['submission_id'] !== null): ?>
                            &middot; <a href="<?= url('/faculty/requirements') ?>">View my requirements</a>
                        <?php endif; ?>
                    </p>
                </div>
                <?php if ($isUnread): ?>
                    <form method="post" action="<?= url('/notifications/' . $notif['notification_id'] . '/read') ?>" class="inline-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
                        <button type="submit" class="btn-sm btn-secondary">Mark as read</button>
                    </form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
<?php require __DIR__ . '/../partials/footer.php'; ?>
