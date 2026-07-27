<?php
/**
 * @var string $appName
 * @var list<array{period_id:int,school_year:string,semester:string,label:?string,is_active:int}> $periods
 * @var array{period_id:int,school_year:string,semester:string,label:?string,is_active:int}|null $period
 * @var array{statusCounts:array{Pending:int,Submitted:int,Approved:int,'Returned-for-revision':int},total:int,complianceRate:int,overdueCount:int}|null $figures
 * @var list<array{user_id:int,faculty_name:string,total:int,pending:int,submitted:int,approved:int,returned:int}> $facultyCompliance
 * @var list<array{doc_type_id:int,doc_type_name:string,total:int,pending:int,submitted:int,approved:int,returned:int}> $docTypeCompletion
 */
require __DIR__ . '/../../partials/header.php';

$periodLabelFor = static function (array $p): string {
    return $p['label'] ?? ($p['school_year'] . ' — ' . $p['semester'] . ' Semester');
};

$periodLabel = $period !== null ? $periodLabelFor($period) : null;

$percentOf = static function (int $numerator, int $denominator): int {
    return $denominator > 0 ? (int) round($numerator / $denominator * 100) : 0;
};

$csvUrl = static function (string $report, int $periodId): string {
    return url('/admin/reports/export?report=' . $report . '&period_id=' . $periodId);
};
?>
<section class="page-head">
    <div>
        <span class="badge">Secretary</span>
        <h1>Reports<?= $periodLabel !== null ? ' — ' . htmlspecialchars($periodLabel) : '' ?></h1>
        <p class="sub">Compliance and completion reports for an academic period, exportable to CSV or Print / Save as PDF (FR-24, FR-25).</p>
    </div>
    <button type="button" class="btn-sm btn-secondary btn-inline" data-print>Print / Save as PDF</button>
</section>

<p class="print-only">
    <?= htmlspecialchars($appName) ?> — Reports<?= $periodLabel !== null ? ' — ' . htmlspecialchars($periodLabel) : '' ?>
</p>

<?php if ($periods === []): ?>
    <p class="alert alert-err" role="alert">No academic periods exist yet. Create one before running reports.</p>
<?php else: ?>
    <form method="get" action="<?= url('/admin/reports') ?>" class="filter-form">
        <div class="field">
            <label for="period_id">Academic period</label>
            <select id="period_id" name="period_id">
                <?php foreach ($periods as $option): ?>
                    <?php $optionId = (int) $option['period_id']; ?>
                    <option value="<?= $optionId ?>" <?= $period !== null && (int) $period['period_id'] === $optionId ? 'selected' : '' ?>>
                        <?= htmlspecialchars($periodLabelFor($option)) ?><?= (int) $option['is_active'] === 1 ? ' (Active)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn-sm btn-primary-sm">View report</button>
    </form>
<?php endif; ?>

<?php if ($period === null): ?>
    <p class="alert alert-err" role="alert">
        No active academic period is set and no period was selected. Choose a period above, or activate one, to see report figures.
    </p>
<?php else: ?>
    <?php $periodId = (int) $period['period_id']; ?>

    <h2 class="section-title">Summary</h2>
    <section class="cards">
        <article class="card">
            <h2>Total Submissions</h2>
            <p class="status"><?= (int) $figures['total'] ?></p>
        </article>

        <article class="card">
            <h2>Pending</h2>
            <p class="status"><?= (int) $figures['statusCounts']['Pending'] ?></p>
        </article>

        <article class="card">
            <h2>Submitted</h2>
            <p class="status"><?= (int) $figures['statusCounts']['Submitted'] ?></p>
        </article>

        <article class="card ok">
            <h2>Approved</h2>
            <p class="status"><?= (int) $figures['statusCounts']['Approved'] ?></p>
        </article>

        <article class="card err">
            <h2>Returned</h2>
            <p class="status"><?= (int) $figures['statusCounts']['Returned-for-revision'] ?></p>
        </article>

        <article class="card">
            <h2>Compliance Rate</h2>
            <p class="status"><?= (int) $figures['complianceRate'] ?>%</p>
            <p class="detail">Approved &divide; total submissions.</p>
        </article>
    </section>
    <p class="csv-links">
        <a href="<?= htmlspecialchars($csvUrl('status', $periodId)) ?>" class="btn-sm btn-secondary">Download CSV — Status Summary</a>
    </p>

    <h2 class="section-title">Per-Faculty Compliance</h2>
    <?php if ($facultyCompliance === []): ?>
        <p class="alert alert-err" role="alert">No faculty submissions recorded for this period.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Faculty</th>
                        <th>Total</th>
                        <th>Pending</th>
                        <th>Submitted</th>
                        <th>Approved</th>
                        <th>Returned</th>
                        <th>Compliance %</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($facultyCompliance as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['faculty_name']) ?></td>
                            <td><?= (int) $row['total'] ?></td>
                            <td><?= (int) $row['pending'] ?></td>
                            <td><?= (int) $row['submitted'] ?></td>
                            <td><?= (int) $row['approved'] ?></td>
                            <td><?= (int) $row['returned'] ?></td>
                            <td><?= $percentOf((int) $row['approved'], (int) $row['total']) ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="csv-links">
            <a href="<?= htmlspecialchars($csvUrl('faculty', $periodId)) ?>" class="btn-sm btn-secondary">Download CSV</a>
        </p>
    <?php endif; ?>

    <h2 class="section-title">Per-Document-Type Completion</h2>
    <?php if ($docTypeCompletion === []): ?>
        <p class="alert alert-err" role="alert">No submissions recorded against any document type for this period.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Document Type</th>
                        <th>Total</th>
                        <th>Pending</th>
                        <th>Submitted</th>
                        <th>Approved</th>
                        <th>Returned</th>
                        <th>Completion %</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($docTypeCompletion as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['doc_type_name']) ?></td>
                            <td><?= (int) $row['total'] ?></td>
                            <td><?= (int) $row['pending'] ?></td>
                            <td><?= (int) $row['submitted'] ?></td>
                            <td><?= (int) $row['approved'] ?></td>
                            <td><?= (int) $row['returned'] ?></td>
                            <td><?= $percentOf((int) $row['approved'], (int) $row['total']) ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="csv-links">
            <a href="<?= htmlspecialchars($csvUrl('doctype', $periodId)) ?>" class="btn-sm btn-secondary">Download CSV</a>
        </p>
    <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../../partials/footer.php'; ?>
