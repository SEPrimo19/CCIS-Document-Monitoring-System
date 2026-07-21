<?php
/**
 * @var string $appName
 * @var array{period_id:int,school_year:string,semester:string,label:?string,start_date:?string,end_date:?string,is_active:int}|null $period
 * @var array{statusCounts:array{Pending:int,Submitted:int,Approved:int,'Returned-for-revision':int},total:int,complianceRate:int,overdueCount:int}|null $figures
 * @var list<array{requirement_id:int,title:string,description:?string,deadline:?string,created_at:string,doc_type_name:string,total_count:int,submitted_count:int}> $requirements
 * @var list<array{user_id:int,first_name:string,last_name:string}> $faculty
 * @var array<int,array<int,string>> $grid
 * @var list<array{doc_type_id:int,name:string,description:?string,is_active:int,created_by:?int,created_at:string}> $docTypes
 * @var list<string> $statuses
 * @var array{faculty_name:string,status:string,doc_type_id:?int} $filters
 * @var list<array{submission_id:int,faculty_name:string,title:string,doc_type_id:int,doc_type_name:string,status:string,deadline:?string,submitted_at:?string,file_id:?int}> $results
 */
require __DIR__ . '/../../partials/header.php';

$periodLabel = $period !== null
    ? ($period['label'] ?? ($period['school_year'] . ' — ' . $period['semester'] . ' Semester'))
    : null;

$statusPillClass = [
    'Pending'               => 'status-pill-pending',
    'Submitted'             => 'status-pill-submitted',
    'Approved'              => 'status-pill-approved',
    'Returned-for-revision' => 'status-pill-returned',
];

$today = date('Y-m-d');
?>
<section class="page-head">
    <div>
        <span class="badge">Administrator</span>
        <h1>Monitoring Board<?= $periodLabel !== null ? ' — ' . htmlspecialchars($periodLabel) : '' ?></h1>
        <p class="sub">Faculty compliance figures, the requirement matrix, and submission search (FR-17, FR-18, FR-19, FR-20).</p>
    </div>
</section>

<?php if ($period === null): ?>
    <p class="alert alert-err" role="alert">
        No active academic period is set. Activate a period to see monitoring figures, the compliance matrix, and submission search.
    </p>
<?php else: ?>
    <section class="cards">
        <article class="card">
            <h2>Total Requirements</h2>
            <p class="status"><?= count($requirements) ?></p>
            <p class="detail">Published for the active period.</p>
        </article>

        <article class="card">
            <h2>Pending</h2>
            <p class="status"><?= (int) $figures['statusCounts']['Pending'] ?></p>
            <p class="detail">Not yet submitted.</p>
        </article>

        <article class="card">
            <h2>Submitted</h2>
            <p class="status"><?= (int) $figures['statusCounts']['Submitted'] ?></p>
            <p class="detail">Awaiting review.</p>
        </article>

        <article class="card ok">
            <h2>Approved</h2>
            <p class="status"><?= (int) $figures['statusCounts']['Approved'] ?></p>
            <p class="detail">Reviewed and accepted.</p>
        </article>

        <article class="card err">
            <h2>Returned</h2>
            <p class="status"><?= (int) $figures['statusCounts']['Returned-for-revision'] ?></p>
            <p class="detail">Sent back for revision.</p>
        </article>

        <article class="card">
            <h2>Compliance Rate</h2>
            <p class="status"><?= (int) $figures['complianceRate'] ?>%</p>
            <p class="detail">Approved &divide; total submissions.</p>
        </article>

        <article class="card<?= $figures['overdueCount'] > 0 ? ' err' : '' ?>">
            <h2>Overdue</h2>
            <p class="status"><?= (int) $figures['overdueCount'] ?></p>
            <p class="detail">Past deadline, not yet approved (FR-20).</p>
        </article>
    </section>

    <h2 class="section-title">Compliance Matrix</h2>
    <?php if ($requirements === []): ?>
        <p class="alert alert-err" role="alert">No requirements published yet for this period.</p>
    <?php elseif ($faculty === []): ?>
        <p class="alert alert-err" role="alert">No active faculty accounts to display.</p>
    <?php else: ?>
        <div class="table-wrap matrix-wrap">
            <table class="table matrix-table">
                <thead>
                    <tr>
                        <th class="matrix-faculty-col">Faculty</th>
                        <?php foreach ($requirements as $req): ?>
                            <th>
                                <?= htmlspecialchars($req['title']) ?>
                                <span class="matrix-col-meta"><?= htmlspecialchars($req['doc_type_name']) ?></span>
                                <span class="matrix-col-meta">
                                    <?= $req['deadline'] !== null ? htmlspecialchars(date('M j, Y', strtotime($req['deadline']))) : 'No deadline' ?>
                                </span>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($faculty as $person): ?>
                        <?php $facultyId = (int) $person['user_id']; ?>
                        <tr>
                            <td class="matrix-faculty-col"><?= htmlspecialchars($person['first_name'] . ' ' . $person['last_name']) ?></td>
                            <?php foreach ($requirements as $req): ?>
                                <?php
                                $requirementId = (int) $req['requirement_id'];
                                $status = $grid[$facultyId][$requirementId] ?? null;
                                $isOverdue = $status !== null
                                    && $req['deadline'] !== null
                                    && $req['deadline'] < $today
                                    && $status !== 'Approved';
                                $pillClass = $status !== null ? ($statusPillClass[$status] ?? 'status-pill-pending') : '';
                                ?>
                                <td class="matrix-cell<?= $isOverdue ? ' matrix-cell-overdue' : '' ?>">
                                    <?php if ($status !== null): ?>
                                        <span class="status-pill <?= $pillClass ?>"><?= htmlspecialchars($status) ?></span>
                                        <?php if ($isOverdue): ?><span class="overdue">Overdue</span><?php endif; ?>
                                    <?php else: ?>
                                        &mdash;
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <h2 class="section-title">Submission Search</h2>
    <form method="get" action="<?= url('/admin/monitoring') ?>" class="filter-form">
        <div class="field">
            <label for="faculty_name">Faculty name</label>
            <input type="text" id="faculty_name" name="faculty_name" value="<?= htmlspecialchars($filters['faculty_name']) ?>" placeholder="Search by name">
        </div>
        <div class="field">
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="">All statuses</option>
                <?php foreach ($statuses as $statusOption): ?>
                    <option value="<?= htmlspecialchars($statusOption) ?>" <?= $filters['status'] === $statusOption ? 'selected' : '' ?>>
                        <?= htmlspecialchars($statusOption) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="doc_type_id">Document type</label>
            <select id="doc_type_id" name="doc_type_id">
                <option value="">All document types</option>
                <?php foreach ($docTypes as $type): ?>
                    <option value="<?= (int) $type['doc_type_id'] ?>" <?= $filters['doc_type_id'] === (int) $type['doc_type_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($type['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn-sm btn-primary-sm">Search</button>
    </form>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Faculty</th>
                    <th>Requirement</th>
                    <th>Document Type</th>
                    <th>Status</th>
                    <th>Deadline</th>
                    <th>Submitted</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($results === []): ?>
                    <tr>
                        <td colspan="7" class="table-empty">No submissions match the current filters.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($results as $row): ?>
                    <?php
                    $isRowOverdue = $row['deadline'] !== null
                        && $row['deadline'] < $today
                        && $row['status'] !== 'Approved';
                    $pillClass = $statusPillClass[$row['status']] ?? 'status-pill-pending';
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($row['faculty_name']) ?></td>
                        <td><?= htmlspecialchars($row['title']) ?></td>
                        <td><?= htmlspecialchars($row['doc_type_name']) ?></td>
                        <td><span class="status-pill <?= $pillClass ?>"><?= htmlspecialchars($row['status']) ?></span></td>
                        <td>
                            <?php if ($row['deadline'] !== null): ?>
                                <?= htmlspecialchars(date('M j, Y', strtotime($row['deadline']))) ?>
                                <?php if ($isRowOverdue): ?><span class="overdue">Overdue</span><?php endif; ?>
                            <?php else: ?>
                                &mdash;
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['submitted_at'] !== null): ?>
                                <?= htmlspecialchars(date('M j, Y', strtotime($row['submitted_at']))) ?>
                            <?php else: ?>
                                &mdash;
                            <?php endif; ?>
                        </td>
                        <td class="table-actions">
                            <?php if ($row['file_id'] !== null): ?>
                                <a href="<?= url('/documents/' . $row['file_id'] . '/download') ?>" class="btn-sm btn-secondary">Download</a>
                            <?php else: ?>
                                <span class="muted-note">&mdash;</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/../../partials/footer.php'; ?>
