<?php
/**
 * Role-aware search results (FR-37).
 *
 * The groups rendered are decided by role in SearchController, not here: a
 * Faculty user's arrays simply arrive empty of anyone else's rows, because the
 * finders bind their own user_id. This view must never be the place ownership
 * is decided — it only lays out what it was given.
 *
 * Every row links into an EXISTING screen rather than restating it: search is
 * a way in, not a second copy of monitoring.
 *
 * @var string $appName
 * @var string $term
 * @var bool $isSecretary
 * @var int $limit
 * @var list<array{user_id:int,first_name:string,last_name:string,email:string,status:string,program_code:?string}> $faculty
 * @var list<array{requirement_id:int,title:string,description:?string,deadline:?string,doc_type_name:string,period_id:int,school_year:string,semester:string,label:?string,is_active:int}> $requirements
 * @var list<array{doc_type_id:int,name:string,description:?string,is_active:int}> $docTypes
 * @var list<array{submission_id:int,status:string,title:string,deadline:?string,doc_type_name:string,period_id:int,school_year:string,semester:string,label:?string,is_active:int}> $mySubmissions
 * @var list<array{file_id:int,file_name:string,version_no:int,uploaded_at:string,submission_id:int,status:string,title:string,doc_type_name:string}> $myFiles
 */
require __DIR__ . '/../partials/header.php';

$statusPillClass = [
    'Pending'   => 'status-pill-pending',
    'Submitted' => 'status-pill-submitted',
    'Approved'  => 'status-pill-approved',
    'Revised'   => 'status-pill-revised',
];

$resultCount = count($faculty) + count($requirements) + count($docTypes)
    + count($mySubmissions) + count($myFiles);

// What each role is told it searched, so an empty result reads as "not in what
// I can see" rather than "not in the system".
$scopeNote = $isSecretary
    ? 'Searches faculty names, requirement titles, and document types.'
    : 'Searches your own requirements and the documents you have uploaded.';
?>
<section class="page-head">
    <div>
        <span class="badge"><?= htmlspecialchars($isSecretary ? 'Secretary' : 'Faculty') ?></span>
        <h1>Search</h1>
        <p class="sub"><?= htmlspecialchars($scopeNote) ?> (FR-37)</p>
    </div>
</section>

<?php if ($term === ''): ?>
    <p class="alert alert-ok" role="status">
        Type a name, requirement, or document type into the search box in the bar above, then press Enter.
    </p>
<?php else: ?>
    <p class="muted-note">
        <?= $resultCount ?> result<?= $resultCount === 1 ? '' : 's' ?> for
        &ldquo;<strong><?= htmlspecialchars($term) ?></strong>&rdquo;.
    </p>

    <?php if ($resultCount === 0): ?>
        <p class="alert alert-err" role="status">
            Nothing matched &ldquo;<?= htmlspecialchars($term) ?>&rdquo;.
            <?= htmlspecialchars($scopeNote) ?>
        </p>
    <?php endif; ?>

    <?php if ($isSecretary): ?>
        <?php if ($faculty !== []): ?>
            <h2 class="section-title">Faculty (<?= count($faculty) ?>)</h2>
            <div class="table-wrap">
                <table class="table table--stack">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Program</th>
                            <th>Account</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($faculty as $row): ?>
                            <?php $fullName = $row['first_name'] . ' ' . $row['last_name']; ?>
                            <tr>
                                <td data-label="Name"><?= htmlspecialchars($fullName) ?></td>
                                <td data-label="Email"><?= htmlspecialchars($row['email']) ?></td>
                                <td data-label="Program"><?= $row['program_code'] !== null ? htmlspecialchars($row['program_code']) : '&mdash;' ?></td>
                                <td data-label="Account">
                                    <span class="status-pill <?= $row['status'] === 'active' ? 'status-pill-active' : 'status-pill-inactive' ?>">
                                        <?= htmlspecialchars(ucfirst($row['status'])) ?>
                                    </span>
                                </td>
                                <td data-label="Action" class="table-actions">
                                    <?php // Into the monitoring board's own Submission Search (FR-19), ?>
                                    <?php // pre-filled with this name — the existing screen that shows ?>
                                    <?php // what this faculty member owes and has submitted. ?>
                                    <a href="<?= htmlspecialchars(url('/admin/monitoring') . '?faculty_name=' . urlencode($fullName)) ?>" class="btn-sm btn-secondary">Submissions</a>
                                    <a href="<?= url('/admin/users/' . (int) $row['user_id'] . '/edit') ?>" class="btn-sm btn-secondary">Account</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($requirements !== []): ?>
            <h2 class="section-title">Requirements (<?= count($requirements) ?>)</h2>
            <div class="table-wrap">
                <table class="table table--stack">
                    <thead>
                        <tr>
                            <th>Requirement</th>
                            <th>Document Type</th>
                            <th>Period</th>
                            <th>Deadline</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requirements as $row): ?>
                            <tr>
                                <td data-label="Requirement"><?= htmlspecialchars($row['title']) ?></td>
                                <td data-label="Document Type"><?= htmlspecialchars($row['doc_type_name']) ?></td>
                                <td data-label="Period"><?= htmlspecialchars(period_label($row)) ?></td>
                                <td data-label="Deadline">
                                    <?= $row['deadline'] !== null ? htmlspecialchars(date('M j, Y', strtotime($row['deadline']))) : '&mdash;' ?>
                                </td>
                                <td data-label="Action" class="table-actions">
                                    <?php // The requirements list only ever shows the ACTIVE period, so ?>
                                    <?php // a hit from a closed one has to go to the archive instead — ?>
                                    <?php // linking both to /admin/requirements would send half of them ?>
                                    <?php // to a screen their row is not on. ?>
                                    <?php if ((int) $row['is_active'] === 1): ?>
                                        <a href="<?= url('/admin/requirements') ?>" class="btn-sm btn-secondary">Requirements</a>
                                    <?php else: ?>
                                        <a href="<?= url('/archive/' . (int) $row['period_id']) ?>" class="btn-sm btn-secondary">Archive</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($docTypes !== []): ?>
            <h2 class="section-title">Document types (<?= count($docTypes) ?>)</h2>
            <div class="table-wrap">
                <table class="table table--stack">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Description</th>
                            <th>State</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($docTypes as $row): ?>
                            <tr>
                                <td data-label="Name"><?= htmlspecialchars($row['name']) ?></td>
                                <td data-label="Description"><?= $row['description'] !== null && $row['description'] !== '' ? htmlspecialchars($row['description']) : '&mdash;' ?></td>
                                <td data-label="State">
                                    <span class="status-pill <?= (int) $row['is_active'] === 1 ? 'status-pill-active' : 'status-pill-inactive' ?>">
                                        <?= (int) $row['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                                <td data-label="Action" class="table-actions">
                                    <a href="<?= url('/admin/document-types/' . (int) $row['doc_type_id'] . '/edit') ?>" class="btn-sm btn-secondary">Open</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <?php if ($mySubmissions !== []): ?>
            <h2 class="section-title">My requirements (<?= count($mySubmissions) ?>)</h2>
            <div class="table-wrap">
                <table class="table table--stack">
                    <thead>
                        <tr>
                            <th>Requirement</th>
                            <th>Document Type</th>
                            <th>Period</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($mySubmissions as $row): ?>
                            <tr>
                                <td data-label="Requirement"><?= htmlspecialchars($row['title']) ?></td>
                                <td data-label="Document Type"><?= htmlspecialchars($row['doc_type_name']) ?></td>
                                <td data-label="Period"><?= htmlspecialchars(period_label($row)) ?></td>
                                <td data-label="Status">
                                    <span class="status-pill <?= $statusPillClass[$row['status']] ?? 'status-pill-pending' ?>">
                                        <?= htmlspecialchars($row['status']) ?>
                                    </span>
                                </td>
                                <td data-label="Action" class="table-actions">
                                    <a href="<?= url('/submissions/' . (int) $row['submission_id']) ?>" class="btn-sm btn-secondary">Details</a>
                                    <?php if ((int) $row['is_active'] === 1): ?>
                                        <a href="<?= url('/faculty/requirements') ?>" class="btn-sm btn-secondary">Checklist</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($myFiles !== []): ?>
            <h2 class="section-title">My uploaded documents (<?= count($myFiles) ?>)</h2>
            <div class="table-wrap">
                <table class="table table--stack">
                    <thead>
                        <tr>
                            <th>File</th>
                            <th>Requirement</th>
                            <th>Version</th>
                            <th>Uploaded</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($myFiles as $row): ?>
                            <tr>
                                <td data-label="File"><?= htmlspecialchars($row['file_name']) ?></td>
                                <td data-label="Requirement"><?= htmlspecialchars($row['title']) ?></td>
                                <td data-label="Version">v<?= (int) $row['version_no'] ?></td>
                                <td data-label="Uploaded"><?= htmlspecialchars(date('M j, Y', strtotime($row['uploaded_at']))) ?></td>
                                <td data-label="Action" class="table-actions">
                                    <a href="<?= url('/documents/' . (int) $row['file_id']) ?>" class="btn-sm btn-primary-sm">View</a>
                                <a href="<?= url('/documents/' . (int) $row['file_id'] . '/download') ?>" class="btn-sm btn-secondary">Download</a>
                                    <a href="<?= url('/submissions/' . (int) $row['submission_id']) ?>" class="btn-sm btn-secondary">Details</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($resultCount > 0): ?>
        <p class="muted-note">
            Each group shows at most <?= (int) $limit ?> matches. Narrow the term, or use the filters on
            <?php if ($isSecretary): ?>
                the <a href="<?= url('/admin/monitoring') ?>">Monitoring board</a>,
            <?php else: ?>
                <a href="<?= url('/faculty/requirements') ?>">My Requirements</a>,
            <?php endif; ?>
            to see everything.
        </p>
    <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../partials/footer.php'; ?>
