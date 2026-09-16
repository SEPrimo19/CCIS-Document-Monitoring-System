<?php
/**
 * @var string $appName
 * @var list<array{user_id:int,first_name:string,last_name:string,email:string,role_id:int,role_name:string,program_code:?string,status:string,created_at:string}> $users
 * @var int $currentUserId
 * @var array{type:string,message:string}|null $flash
 * @var string $csrf
 */
require __DIR__ . '/../../partials/header.php';
?>
<section class="page-head">
    <div>
        <span class="badge">Secretary</span>
        <h1>Users</h1>
        <p class="sub">Create, edit, and deactivate/reactivate accounts, and assign roles and programs (FR-26, FR-36).</p>
    </div>
    <a href="<?= url('/admin/users/new') ?>" class="btn-primary btn-inline">Add user</a>
</section>

<?php if ($flash !== null): ?>
    <p class="alert <?= $flash['type'] === 'ok' ? 'alert-ok' : 'alert-err' ?>" role="alert">
        <?= htmlspecialchars($flash['message']) ?>
    </p>
<?php endif; ?>

<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Program</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($users === []): ?>
                <tr>
                    <td colspan="6" class="table-empty">No users yet.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($users as $u): ?>
                <?php
                $isActive = $u['status'] === 'active';
                $isSelf = (int) $u['user_id'] === $currentUserId;
                ?>
                <tr>
                    <td>
                        <?= htmlspecialchars($u['last_name'] . ', ' . $u['first_name']) ?>
                        <?php if ($isSelf): ?>
                            <span class="muted-note">(you)</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($u['email']) ?></td>
                    <td><span class="role-pill"><?= htmlspecialchars($u['role_name']) ?></span></td>
                    <td><?= $u['program_code'] !== null ? htmlspecialchars($u['program_code']) : '—' ?></td>
                    <td>
                        <span class="status-pill <?= $isActive ? 'status-pill-active' : 'status-pill-inactive' ?>">
                            <?= $isActive ? 'Active' : 'Inactive' ?>
                        </span>
                    </td>
                    <td class="table-actions">
                        <a href="<?= url('/admin/users/' . $u['user_id'] . '/edit') ?>" class="btn-sm btn-secondary">Edit</a>
                        <?php if ($isActive): ?>
                            <?php if ($isSelf): ?>
                                <span class="field-help">Cannot deactivate your own account</span>
                            <?php else: ?>
                                <form method="post" action="<?= url('/admin/users/' . $u['user_id'] . '/deactivate') ?>" class="inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                    <button type="submit" class="btn-sm btn-danger">Deactivate</button>
                                </form>
                            <?php endif; ?>
                        <?php else: ?>
                            <form method="post" action="<?= url('/admin/users/' . $u['user_id'] . '/activate') ?>" class="inline-form">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                <button type="submit" class="btn-sm btn-secondary">Activate</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/../../partials/footer.php'; ?>
