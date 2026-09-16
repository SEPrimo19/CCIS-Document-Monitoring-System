<?php
/**
 * @var string $appName
 * @var array{period_id:int,school_year:string,semester:string,label:?string,start_date:?string,end_date:?string,is_active:int}|null $period
 * @var list<array{submission_id:int,status:string,current_version:int,updated_at:string,title:string,deadline:?string,doc_type_name:string,file_id:?int,file_name:?string,last_comment:?string}> $checklist
 * @var array{type:string,message:string}|null $flash
 * @var string $csrf
 */
require __DIR__ . '/../partials/header.php';

$periodLabel = $period !== null
    ? ($period['label'] ?? ($period['school_year'] . ' — ' . $period['semester'] . ' Semester'))
    : null;

$statusPillClass = [
    'Pending'   => 'status-pill-pending',
    'Submitted' => 'status-pill-submitted',
    'Approved'  => 'status-pill-approved',
    'Revised'   => 'status-pill-revised',
];
?>
<section class="page-head">
    <div>
        <span class="badge">Faculty</span>
        <h1>My Requirements<?= $periodLabel !== null ? ' — ' . htmlspecialchars($periodLabel) : '' ?></h1>
        <p class="sub">Upload against each requirement for the active academic period (FR-6, FR-7, FR-8).</p>
    </div>
</section>

<?php if ($flash !== null): ?>
    <p class="alert <?= $flash['type'] === 'ok' ? 'alert-ok' : 'alert-err' ?>" role="alert">
        <?= htmlspecialchars($flash['message']) ?>
    </p>
<?php endif; ?>

<?php if ($period === null): ?>
    <p class="alert alert-err" role="alert">
        No active academic period is set. Check back once the administrator activates one.
    </p>
<?php else: ?>
    <div class="table-wrap">
        <table class="table table--stack table--checklist">
            <thead>
                <tr>
                    <th>Requirement</th>
                    <th>Document Type</th>
                    <th>Deadline</th>
                    <th>Status</th>
                    <th>Last updated</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($checklist === []): ?>
                    <tr>
                        <td colspan="6" class="table-empty">No requirements assigned for the active period yet.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($checklist as $row): ?>
                    <?php
                    $isOverdue = $row['deadline'] !== null
                        && $row['deadline'] < date('Y-m-d')
                        && $row['status'] !== 'Approved';
                    $canUpload = in_array($row['status'], ['Pending', 'Revised'], true);
                    $hasFile = $row['file_id'] !== null;
                    $pillClass = $statusPillClass[$row['status']] ?? 'status-pill-pending';
                    ?>
                    <tr>
                        <td data-label="Requirement"><?= htmlspecialchars($row['title']) ?></td>
                        <td data-label="Document Type"><?= htmlspecialchars($row['doc_type_name']) ?></td>
                        <td data-label="Deadline">
                            <?php if ($row['deadline'] !== null): ?>
                                <?= htmlspecialchars(date('M j, Y', strtotime($row['deadline']))) ?>
                                <?php if ($isOverdue): ?><span class="overdue">Overdue</span><?php endif; ?>
                            <?php else: ?>
                                &mdash;
                            <?php endif; ?>
                        </td>
                        <td data-label="Status">
                            <span class="status-pill <?= $pillClass ?>"><?= htmlspecialchars($row['status']) ?></span>
                            <?php if ($row['status'] === 'Revised' && $row['last_comment'] !== null && $row['last_comment'] !== ''): ?>
                                <p class="review-comment-note"><strong>Reviewer:</strong> <?= htmlspecialchars($row['last_comment']) ?></p>
                            <?php endif; ?>
                        </td>
                        <td data-label="Last updated"><?= htmlspecialchars(date('M j, Y', strtotime($row['updated_at']))) ?></td>
                        <td class="table-actions" data-label="Action">
                            <?php if ($canUpload): ?>
                                <form method="post" enctype="multipart/form-data" action="<?= url('/faculty/submissions/' . $row['submission_id'] . '/upload') ?>" class="upload-form">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                    <?php // FR-7: still a plain POST + CSRF + multipart submit. The ?>
                                    <?php // <label> wraps the input, so clicking it opens the picker ?>
                                    <?php // with no script involved; app.js only swaps the text below ?>
                                    <?php // for the chosen filename once it is running. ?>
                                    <label class="file-field" data-file-field>
                                        <input class="file-field-input" type="file" name="document" accept=".pdf,.doc,.docx" required data-file-input>
                                        <span class="file-field-text" data-file-text>Choose file</span>
                                    </label>
                                    <button type="submit" class="btn-sm btn-primary-sm">Upload</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($hasFile): ?>
                                <a href="<?= url('/documents/' . $row['file_id']) ?>" class="btn-sm btn-primary-sm">View</a>
                                <a href="<?= url('/documents/' . $row['file_id'] . '/download') ?>" class="btn-sm btn-secondary">Download</a>
                            <?php endif; ?>
                            <?php // Always last, and always on its own line at desktop (see ?>
                            <?php // .action-details) so every Action cell reads the same way: ?>
                            <?php // what you can DO with this requirement, then where to ?>
                            <?php // read about it. ?>
                            <a href="<?= url('/submissions/' . $row['submission_id']) ?>" class="btn-sm btn-secondary action-details">Details</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/../partials/footer.php'; ?>
