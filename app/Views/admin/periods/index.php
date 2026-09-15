<?php
/**
 * @var string $appName
 * @var list<array{period_id:int,school_year:string,semester:string,label:?string,start_date:?string,end_date:?string,is_active:int,requirement_count:int,submission_count:int}> $periods
 * @var array{type:string,message:string}|null $flash
 * @var string $csrf
 */
require __DIR__ . '/../../partials/header.php';

$hasActive = false;
// Name of the period currently active, so the "make active" confirmation can
// say which one is about to be closed rather than warning in the abstract.
$activeName = null;
foreach ($periods as $p) {
    if ((int) $p['is_active'] === 1) {
        $hasActive = true;
        $activeName = period_label($p);
        break;
    }
}
?>
<section class="page-head">
    <div>
        <span class="badge">Secretary</span>
        <h1>Academic Periods</h1>
        <p class="sub">Create school year and semester periods, and choose which one is active (FR-29).</p>
    </div>
    <a href="<?= url('/admin/periods/new') ?>" class="btn-primary btn-inline">New academic period</a>
</section>

<?php if ($flash !== null): ?>
    <p class="alert <?= $flash['type'] === 'ok' ? 'alert-ok' : 'alert-err' ?>" role="alert">
        <?= htmlspecialchars($flash['message']) ?>
    </p>
<?php endif; ?>

<?php if (!$hasActive): ?>
    <p class="alert alert-err" role="alert">
        No period is active. Faculty checklists, the review queue, and the monitoring board stay empty
        until you activate one.
    </p>
<?php endif; ?>

<p class="muted-note">
    Only one period is active at a time &mdash; activating a period automatically closes the previous one
    and moves it to the <a href="<?= url('/archive') ?>">archive</a>. Periods are never deleted, so past
    submissions and decisions are always preserved.
</p>

<div class="table-wrap">
    <table class="table table--stack">
        <thead>
            <tr>
                <th>Period</th>
                <th>Dates</th>
                <th>Requirements</th>
                <th>Submissions</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($periods === []): ?>
                <tr>
                    <td colspan="6" class="table-empty">No academic periods yet. Create one to get started.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($periods as $period): ?>
                <?php
                $isActive = (int) $period['is_active'] === 1;
                $name = period_label($period);
                ?>
                <tr>
                    <td data-label="Period">
                        <strong><?= htmlspecialchars($name) ?></strong>
                        <p class="muted-note"><?= htmlspecialchars($period['school_year']) ?> &middot; <?= htmlspecialchars($period['semester']) ?></p>
                    </td>
                    <td data-label="Dates">
                        <?php if ($period['start_date'] !== null || $period['end_date'] !== null): ?>
                            <?= $period['start_date'] !== null ? htmlspecialchars(date('M j, Y', strtotime($period['start_date']))) : '&mdash;' ?>
                            &ndash;
                            <?= $period['end_date'] !== null ? htmlspecialchars(date('M j, Y', strtotime($period['end_date']))) : '&mdash;' ?>
                        <?php else: ?>
                            &mdash;
                        <?php endif; ?>
                    </td>
                    <td data-label="Requirements"><?= (int) $period['requirement_count'] ?></td>
                    <td data-label="Submissions"><?= (int) $period['submission_count'] ?></td>
                    <td data-label="Status">
                        <span class="status-pill <?= $isActive ? 'status-pill-active' : 'status-pill-inactive' ?>">
                            <?= $isActive ? 'Active' : 'Archived' ?>
                        </span>
                    </td>
                    <td class="table-actions" data-label="Actions">
                        <a href="<?= url('/admin/periods/' . $period['period_id'] . '/edit') ?>" class="btn-sm btn-secondary">Edit</a>
                        <?php // Both actions silently reshape every other screen — closing a ?>
                        <?php // period empties the checklists, review queue and monitoring ?>
                        <?php // board; activating one closes whichever period is open. They ?>
                        <?php // are one click next to a plain "Edit", so they are guarded by ?>
                        <?php // data-confirm (wired in app.js — the CSP blocks inline onclick). ?>
                        <?php if ($isActive): ?>
                            <form method="post" action="<?= url('/admin/periods/' . $period['period_id'] . '/deactivate') ?>" class="inline-form"
                                  data-confirm="Close &quot;<?= htmlspecialchars($name) ?>&quot;?&#10;&#10;No period will be active. Faculty checklists, the review queue and the monitoring board stay empty until you activate another one.&#10;&#10;Nothing is deleted — this period moves to the archive.">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                <button type="submit" class="btn-sm btn-danger">Close period</button>
                            </form>
                        <?php else: ?>
                            <form method="post" action="<?= url('/admin/periods/' . $period['period_id'] . '/activate') ?>" class="inline-form"
                                  data-confirm="Make &quot;<?= htmlspecialchars($name) ?>&quot; the active period?&#10;&#10;<?= $activeName !== null ? 'This closes &quot;' . htmlspecialchars($activeName) . '&quot; and moves it to the archive.&#10;' : '' ?>Faculty checklists, the review queue and the monitoring board all switch to the new period.&#10;&#10;Nothing is deleted.">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                <button type="submit" class="btn-sm btn-primary-sm">Make active</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/../../partials/footer.php'; ?>
