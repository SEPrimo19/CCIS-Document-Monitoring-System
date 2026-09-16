<?php
/**
 * @var string $appName
 * @var array{period_id:int,school_year:string,semester:string,label:?string,start_date:?string,end_date:?string,is_active:int} $period
 * @var list<array{submission_id:int,status:string,current_version:int,updated_at:string,title:string,deadline:?string,doc_type_name:string,faculty_name:string,file_id:?int,file_name:?string}> $submissions
 * @var bool $ownOnly true when the viewer is Faculty and sees only their own history
 */
require __DIR__ . '/../partials/header.php';

$name = $period['label'] ?? ('AY ' . $period['school_year'] . ', ' . $period['semester'] . ' Semester');
$isActive = (int) $period['is_active'] === 1;

$statusPillClass = [
    'Pending'   => 'status-pill-pending',
    'Submitted' => 'status-pill-submitted',
    'Approved'  => 'status-pill-approved',
    'Revised'   => 'status-pill-revised',
];
?>
<section class="page-head">
    <div>
        <span class="badge">Archive</span>
        <h1><?= htmlspecialchars($name) ?></h1>
        <p class="sub">
            <?= $ownOnly ? 'Your submissions for this period' : 'All faculty submissions for this period' ?>
            &middot; read-only.
        </p>
    </div>
    <a href="<?= url('/archive') ?>" class="btn-sm btn-secondary">Back to archive</a>
</section>

<?php if ($isActive): ?>
    <p class="alert alert-ok" role="alert">
        This is the currently active period, so it is still being worked on. It becomes a closed archive
        entry once the administrator activates a different period.
    </p>
<?php endif; ?>

<div class="table-wrap">
    <table class="table table--stack">
        <thead>
            <tr>
                <?php if (!$ownOnly): ?><th>Faculty</th><?php endif; ?>
                <th>Requirement</th>
                <th>Document Type</th>
                <th>Deadline</th>
                <th>Status</th>
                <th>Last updated</th>
                <th>Document</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($submissions === []): ?>
                <tr>
                    <td colspan="<?= $ownOnly ? 6 : 7 ?>" class="table-empty">
                        No submissions were recorded for this period.
                    </td>
                </tr>
            <?php endif; ?>
            <?php foreach ($submissions as $row): ?>
                <?php $pillClass = $statusPillClass[$row['status']] ?? 'status-pill-pending'; ?>
                <tr>
                    <?php if (!$ownOnly): ?>
                        <td data-label="Faculty"><?= htmlspecialchars($row['faculty_name']) ?></td>
                    <?php endif; ?>
                    <td data-label="Requirement"><?= htmlspecialchars($row['title']) ?></td>
                    <td data-label="Document Type"><?= htmlspecialchars($row['doc_type_name']) ?></td>
                    <td data-label="Deadline">
                        <?= $row['deadline'] !== null ? htmlspecialchars(date('M j, Y', strtotime($row['deadline']))) : '&mdash;' ?>
                    </td>
                    <td data-label="Status">
                        <span class="status-pill <?= $pillClass ?>"><?= htmlspecialchars($row['status']) ?></span>
                    </td>
                    <td data-label="Last updated"><?= htmlspecialchars(date('M j, Y', strtotime($row['updated_at']))) ?></td>
                    <td class="table-actions" data-label="Document">
                        <a href="<?= url('/submissions/' . $row['submission_id']) ?>" class="btn-sm btn-secondary">Details</a>
                        <?php if ($row['file_id'] !== null): ?>
                            <a href="<?= url('/documents/' . $row['file_id']) ?>" class="btn-sm btn-primary-sm">View</a>
                                <a href="<?= url('/documents/' . $row['file_id'] . '/download') ?>" class="btn-sm btn-secondary">Download</a>
                        <?php else: ?>
                            <span class="muted-note">Never submitted</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
