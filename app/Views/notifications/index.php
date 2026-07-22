<?php
/**
 * @var string $appName
 * @var list<array{notification_id:int,user_id:int,submission_id:?int,type:string,title:string,message:string,is_read:int,created_at:string}> $notifications
 * @var array{type:string,message:string}|null $flash
 * @var string $csrf
 */
require __DIR__ . '/../partials/header.php';

$unreadCount = count(array_filter($notifications, static fn(array $n): bool => (int) $n['is_read'] === 0));
$isFaculty = \App\Core\Auth::hasRole('Faculty');
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
            <button type="submit" class="btn-sm btn-secondary">Mark all as read</button>
        </form>
    <?php endif; ?>
</section>

<?php if ($flash !== null): ?>
    <p class="alert <?= $flash['type'] === 'ok' ? 'alert-ok' : 'alert-err' ?>" role="alert">
        <?= htmlspecialchars($flash['message']) ?>
    </p>
<?php endif; ?>

<?php if ($notifications === []): ?>
    <p class="muted-note">You have no notifications.</p>
<?php else: ?>
    <ul class="notif-list">
        <?php foreach ($notifications as $notif): ?>
            <?php $isUnread = (int) $notif['is_read'] === 0; ?>
            <li class="notif-item<?= $isUnread ? ' unread' : '' ?>">
                <div class="notif-body">
                    <p class="notif-title">
                        <?php if ($isUnread): ?><span class="notif-dot" aria-hidden="true"></span><?php endif; ?>
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
                        <button type="submit" class="btn-sm btn-secondary">Mark as read</button>
                    </form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
<?php require __DIR__ . '/../partials/footer.php'; ?>
