<?php
/**
 * @var string $appName
 * @var array{period_id:int,school_year:string,semester:string,label:?string,start_date:?string,end_date:?string,is_active:int}|null $period
 * @var list<array{requirement_id:int,title:string,description:?string,deadline:?string,created_at:string,doc_type_name:string,applies_to:string,target_program_code:?string,target_count:int,total_count:int,submitted_count:int}> $requirements
 * @var array{type:string,message:string}|null $flash
 * @var string $csrf
 */
require __DIR__ . '/../../partials/header.php';

$periodLabel = $period !== null
    ? ($period['label'] ?? ($period['school_year'] . ' — ' . $period['semester'] . ' Semester'))
    : null;

/**
 * The audience a requirement was published to (FR-35), as one short phrase.
 * Reads the payload that matches applies_to and nothing else, so a stale
 * target_program_id left behind by an earlier edit can never be displayed as
 * though it were in force.
 *
 * @param array{applies_to:string,target_program_code:?string,target_count:int} $req
 */
$audienceLabel = static function (array $req): string {
    if ($req['applies_to'] === 'program') {
        // A program deactivated after publishing still has its code here (the
        // join is on program_id, not on is_active), so this stays accurate.
        return $req['target_program_code'] !== null
            ? 'Program: ' . $req['target_program_code']
            : 'Program (removed)';
    }

    if ($req['applies_to'] === 'individual') {
        return (int) $req['target_count'] . ' selected faculty';
    }

    return 'All faculty';
};
?>
<section class="page-head">
    <div>
        <span class="badge">Secretary</span>
        <h1>Requirements<?= $periodLabel !== null ? ' — ' . htmlspecialchars($periodLabel) : '' ?></h1>
        <p class="sub">Define a requirement and publish it — to all faculty, one program, or individually chosen faculty — for the active academic period (FR-28, FR-35).</p>
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
                    <th>Applies to</th>
                    <th>Deadline</th>
                    <th>Progress</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($requirements === []): ?>
                    <tr>
                        <td colspan="6" class="table-empty">No requirements published yet for this period.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($requirements as $req): ?>
                    <tr>
                        <td><?= htmlspecialchars($req['title']) ?></td>
                        <td><?= htmlspecialchars($req['doc_type_name']) ?></td>
                        <td><?= htmlspecialchars($audienceLabel($req)) ?></td>
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
