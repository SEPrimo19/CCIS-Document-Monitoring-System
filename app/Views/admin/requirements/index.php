<?php
/**
 * @var string $appName
 * @var array{period_id:int,school_year:string,semester:string,label:?string,start_date:?string,end_date:?string,is_active:int}|null $period
 * @var list<array{requirement_id:int,title:string,description:?string,deadline:?string,created_at:string,doc_type_name:string,total_count:int,submitted_count:int}> $requirements
 * @var array{type:string,message:string}|null $flash
 * @var string $csrf
 */
require __DIR__ . '/../../partials/header.php';

$periodLabel = $period !== null
    ? ($period['label'] ?? ($period['school_year'] . ' — ' . $period['semester'] . ' Semester'))
    : null;
?>
<section class="page-head">
    <div>
        <span class="badge">Secretary</span>
        <h1>Requirements<?= $periodLabel !== null ? ' — ' . htmlspecialchars($periodLabel) : '' ?></h1>
        <p class="sub">Define a requirement and publish it to all faculty for the active academic period (FR-28).</p>
    </div>
    <?php if ($period !== null): ?>
        <a href="<?= url('/admin/requirements/new') ?>" class="btn-primary btn-inline">New requirement</a>
    <?php endif; ?>
</section>

<?php if ($flash !== null): ?>
    <p class="alert <?= $flash['type'] === 'ok' ? 'alert-ok' : 'alert-err' ?>" role="alert">
        <?= htmlspecialchars($flash['message']) ?>
    </p>
<?php endif; ?>

<?php if ($period === null): ?>
    <p class="alert alert-err" role="alert">
        No active academic period is set. Activate a period before requirements can be published.
    </p>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Document Type</th>
                    <th>Deadline</th>
                    <th>Progress</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($requirements === []): ?>
                    <tr>
                        <td colspan="5" class="table-empty">No requirements published yet for this period.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($requirements as $req): ?>
                    <tr>
                        <td><?= htmlspecialchars($req['title']) ?></td>
                        <td><?= htmlspecialchars($req['doc_type_name']) ?></td>
                        <td><?= $req['deadline'] !== null ? htmlspecialchars(date('M j, Y', strtotime($req['deadline']))) : '—' ?></td>
                        <td><?= (int) $req['submitted_count'] ?>/<?= (int) $req['total_count'] ?> submitted</td>
                        <td><?= htmlspecialchars(date('M j, Y', strtotime($req['created_at']))) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/../../partials/footer.php'; ?>
