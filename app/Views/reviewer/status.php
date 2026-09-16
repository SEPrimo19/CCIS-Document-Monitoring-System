<?php
/**
 * One status's submissions for the active academic period (FR-38) — the screen
 * behind each entry of the sidebar's Review status sub-navigation.
 *
 * Deliberately NOT a fourth filter control: the status is fixed by the route,
 * so the screen the client asked for ("each screen must display those filter
 * status of the faculty") is one click from the sidebar with nothing to set.
 * The monitoring board's Submission Search (FR-19) remains the place to
 * combine status with a faculty name or document type.
 *
 * @var string $appName
 * @var array{period_id:int,school_year:string,semester:string,label:?string,start_date:?string,end_date:?string,is_active:int}|null $period
 * @var string $status
 * @var list<array{submission_id:int,faculty_name:string,title:string,doc_type_id:int,doc_type_name:string,status:string,deadline:?string,submitted_at:?string,file_id:?int}> $rows
 */
require __DIR__ . '/../partials/header.php';

$periodLabel = $period !== null ? period_label($period) : null;

$statusPillClass = [
    'Pending'   => 'status-pill-pending',
    'Submitted' => 'status-pill-submitted',
    'Approved'  => 'status-pill-approved',
    'Revised'   => 'status-pill-revised',
];
$pillClass = $statusPillClass[$status] ?? 'status-pill-pending';

// One sentence per status, so the screen says what the list MEANS rather than
// just repeating its own title.
$statusBlurb = [
    'Pending'   => 'Requirements no faculty member has uploaded a document for yet.',
    'Submitted' => 'Documents uploaded and waiting for your verification — the Review Queue is where you act on them.',
    'Approved'  => 'Documents you have verified and accepted.',
    'Revised'   => 'Documents you returned for revision; the faculty member has been notified and may resubmit.',
];

$today = date('Y-m-d');
?>
<section class="page-head">
    <div>
        <span class="badge">Secretary</span>
        <h1><?= htmlspecialchars($status) ?> Submissions<?= $periodLabel !== null ? ' — ' . htmlspecialchars($periodLabel) : '' ?></h1>
        <p class="sub"><?= htmlspecialchars($statusBlurb[$status] ?? '') ?> (FR-38)</p>
    </div>
</section>

<?php if ($period === null): ?>
    <p class="alert alert-err" role="alert">
        No active academic period is set. Activate one under
        <a href="<?= url('/admin/periods') ?>">Periods</a> before there is anything to list here.
    </p>
<?php else: ?>
    <p class="muted-note">
        <?= count($rows) ?> submission<?= count($rows) === 1 ? '' : 's' ?> with status
        <span class="status-pill <?= $pillClass ?>"><?= htmlspecialchars($status) ?></span>
        in this period.
    </p>

    <div class="table-wrap">
        <table class="table table--stack">
            <thead>
                <tr>
                    <th>Faculty</th>
                    <th>Requirement</th>
                    <th>Document Type</th>
                    <th>Deadline</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="5" class="table-empty">
                            No submissions are <?= htmlspecialchars(strtolower($status)) ?> for the active period.
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <?php
                    // Same overdue rule as the monitoring board (FR-20): past
                    // the deadline and not yet Approved.
                    $isRowOverdue = $row['deadline'] !== null
                        && $row['deadline'] < $today
                        && $row['status'] !== 'Approved';
                    ?>
                    <tr>
                        <td data-label="Faculty"><?= htmlspecialchars($row['faculty_name']) ?></td>
                        <td data-label="Requirement"><?= htmlspecialchars($row['title']) ?></td>
                        <td data-label="Document Type"><?= htmlspecialchars($row['doc_type_name']) ?></td>
                        <td data-label="Deadline">
                            <?php if ($row['deadline'] !== null): ?>
                                <?= htmlspecialchars(date('M j, Y', strtotime($row['deadline']))) ?>
                                <?php if ($isRowOverdue): ?><span class="overdue">Overdue</span><?php endif; ?>
                            <?php else: ?>
                                &mdash;
                            <?php endif; ?>
                        </td>
                        <td data-label="Action" class="table-actions">
                            <a href="<?= url('/submissions/' . (int) $row['submission_id']) ?>" class="btn-sm btn-secondary">Details</a>
                            <?php if ($status === 'Submitted'): ?>
                                <?php // The one status with an action attached: Review Queue owns ?>
                                <?php // it, but a Secretary who arrived here should not have to go ?>
                                <?php // back for the decision screen. ?>
                                <a href="<?= url('/reviewer/submissions/' . (int) $row['submission_id'] . '/review') ?>" class="btn-sm btn-primary-sm">Review</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/../partials/footer.php'; ?>
