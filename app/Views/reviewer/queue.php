<?php
/**
 * @var string $appName
 * @var array{period_id:int,school_year:string,semester:string,label:?string,start_date:?string,end_date:?string,is_active:int}|null $period
 * @var list<array{doc_type_id:int,name:string,description:?string,is_active:int,created_by:?int,created_at:string}> $docTypes
 * @var int|null $selectedDocTypeId
 * @var list<array{submission_id:int,status:string,current_version:int,submitted_at:?string,title:string,doc_type_id:int,doc_type_name:string,faculty_name:string,file_id:?int}> $queue
 * @var array{type:string,message:string}|null $flash
 * @var string $csrf
 */
require __DIR__ . '/../partials/header.php';

$periodLabel = $period !== null
    ? ($period['label'] ?? ($period['school_year'] . ' — ' . $period['semester'] . ' Semester'))
    : null;
?>
<section class="page-head">
    <div>
        <span class="badge">Reviewer/Approver</span>
        <h1>Review Queue<?= $periodLabel !== null ? ' — ' . htmlspecialchars($periodLabel) : '' ?></h1>
        <p class="sub">Submitted documents awaiting a decision — a shared queue open to every reviewer (FR-12).</p>
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
    <form method="get" action="<?= url('/reviewer/queue') ?>" class="filter-form">
        <div class="field">
            <label for="doc_type_id">Document type</label>
            <select id="doc_type_id" name="doc_type_id">
                <option value="">All document types</option>
                <?php foreach ($docTypes as $type): ?>
                    <option value="<?= (int) $type['doc_type_id'] ?>" <?= $selectedDocTypeId === (int) $type['doc_type_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($type['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn-sm btn-primary-sm">Filter</button>
    </form>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Faculty</th>
                    <th>Requirement</th>
                    <th>Document Type</th>
                    <th>Submitted</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($queue === []): ?>
                    <tr>
                        <td colspan="5" class="table-empty">No documents awaiting review.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($queue as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['faculty_name']) ?></td>
                        <td><?= htmlspecialchars($row['title']) ?></td>
                        <td><?= htmlspecialchars($row['doc_type_name']) ?></td>
                        <td>
                            <?php if ($row['submitted_at'] !== null): ?>
                                <?= htmlspecialchars(date('M j, Y', strtotime($row['submitted_at']))) ?>
                            <?php else: ?>
                                &mdash;
                            <?php endif; ?>
                        </td>
                        <td class="table-actions">
                            <a href="<?= url('/reviewer/submissions/' . $row['submission_id'] . '/review') ?>" class="btn-sm btn-primary-sm">Review</a>
                            <?php if ($row['file_id'] !== null): ?>
                                <a href="<?= url('/documents/' . $row['file_id'] . '/download') ?>" class="btn-sm btn-secondary">Download</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/../partials/footer.php'; ?>
