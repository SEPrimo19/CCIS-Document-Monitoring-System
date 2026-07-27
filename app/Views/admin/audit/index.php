<?php
/**
 * @var string $appName
 * @var list<array{log_id:int,action:string,entity_type:string,entity_id:?int,details:?string,ip_address:?string,created_at:string,actor_name:string,actor_role:string}> $entries
 * @var list<array{user_id:int,actor_name:string}> $actors
 * @var list<string> $actions
 * @var array{user_id:?int,action:string,date_from:string,date_to:string} $filters
 * @var int $limit
 */
require __DIR__ . '/../../partials/header.php';
?>
<section class="page-head">
    <div>
        <span class="badge">Secretary</span>
        <h1>Audit Log</h1>
        <p class="sub">Every recorded system action, newest first (FR-30, FR-31). The log is append-only — entries are never edited or deleted through the app — so it stands as the accountability trail.</p>
    </div>
</section>

<form method="get" action="<?= url('/admin/audit-log') ?>" class="filter-form">
    <div class="field">
        <label for="user_id">User</label>
        <select id="user_id" name="user_id">
            <option value="">All users</option>
            <?php foreach ($actors as $actor): ?>
                <option value="<?= (int) $actor['user_id'] ?>" <?= $filters['user_id'] === (int) $actor['user_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($actor['actor_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="action">Action</label>
        <select id="action" name="action">
            <option value="">All actions</option>
            <?php foreach ($actions as $actionOption): ?>
                <option value="<?= htmlspecialchars($actionOption) ?>" <?= $filters['action'] === $actionOption ? 'selected' : '' ?>>
                    <?= htmlspecialchars($actionOption) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="date_from">From</label>
        <input type="date" id="date_from" name="date_from" value="<?= htmlspecialchars($filters['date_from']) ?>">
    </div>
    <div class="field">
        <label for="date_to">To</label>
        <input type="date" id="date_to" name="date_to" value="<?= htmlspecialchars($filters['date_to']) ?>">
    </div>
    <button type="submit" class="btn-sm btn-primary-sm">Search</button>
</form>

<?php if (count($entries) >= $limit): ?>
    <p class="muted-note">Showing the most recent <?= (int) $limit ?> entries. Narrow the filters above to see older entries.</p>
<?php endif; ?>

<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>When</th>
                <th>Actor</th>
                <th>Action</th>
                <th>Entity</th>
                <th>Details</th>
                <th>IP</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($entries === []): ?>
                <tr>
                    <td colspan="6" class="table-empty">No audit entries match the current filters.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($entries as $entry): ?>
                <tr>
                    <td><?= htmlspecialchars(date('M j, Y g:i A', strtotime($entry['created_at']))) ?></td>
                    <td>
                        <?= htmlspecialchars($entry['actor_name']) ?>
                        <span class="role-pill"><?= htmlspecialchars($entry['actor_role']) ?></span>
                    </td>
                    <td><span class="action-label"><?= htmlspecialchars($entry['action']) ?></span></td>
                    <td>
                        <?= htmlspecialchars($entry['entity_type']) ?><?php if ($entry['entity_id'] !== null): ?> #<?= (int) $entry['entity_id'] ?><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($entry['details'] !== null): ?>
                            <?= htmlspecialchars($entry['details']) ?>
                        <?php else: ?>
                            &mdash;
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($entry['ip_address'] !== null): ?>
                            <span class="ip-address"><?= htmlspecialchars($entry['ip_address']) ?></span>
                        <?php else: ?>
                            &mdash;
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/../../partials/footer.php'; ?>
