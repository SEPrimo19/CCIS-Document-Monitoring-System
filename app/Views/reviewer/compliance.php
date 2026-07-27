<?php
/**
 * Secretary's read-only faculty-compliance summary (FR-16). Reuses the
 * same per-faculty figures the admin monitoring/reports use, scoped to the
 * active academic period.
 *
 * @var string $appName
 * @var array{period_id:int,school_year:string,semester:string,label:?string,start_date:?string,end_date:?string,is_active:int}|null $period
 * @var list<array{user_id:int,faculty_name:string,total:int,pending:int,submitted:int,approved:int,returned:int}> $compliance
 */
require __DIR__ . '/../partials/header.php';

$periodLabel = $period !== null
    ? ($period['label'] ?? ($period['school_year'] . ' — ' . $period['semester'] . ' Semester'))
    : null;
?>
<section class="page-head">
    <div>
        <span class="badge">Secretary</span>
        <h1>Faculty Compliance<?= $periodLabel !== null ? ' — ' . htmlspecialchars($periodLabel) : '' ?></h1>
        <p class="sub">Per-faculty summary of submitted, approved, returned, and pending requirements for the active period (FR-16).</p>
    </div>
</section>

<?php if ($period === null): ?>
    <p class="alert alert-err" role="alert">
        No active academic period is set. Check back once the administrator activates one.
    </p>
<?php else: ?>
    <div class="table-wrap">
        <table class="table table--stack">
            <thead>
                <tr>
                    <th>Faculty</th>
                    <th>Submitted</th>
                    <th>Approved</th>
                    <th>Returned</th>
                    <th>Pending</th>
                    <th>Total</th>
                    <th>Compliance</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($compliance === []): ?>
                    <tr>
                        <td colspan="7" class="table-empty">No faculty submissions for the active period yet.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($compliance as $row): ?>
                    <?php $pct = $row['total'] > 0 ? (int) round($row['approved'] / $row['total'] * 100) : 0; ?>
                    <tr>
                        <td data-label="Faculty"><?= htmlspecialchars($row['faculty_name']) ?></td>
                        <td data-label="Submitted"><?= (int) $row['submitted'] ?></td>
                        <td data-label="Approved"><?= (int) $row['approved'] ?></td>
                        <td data-label="Returned"><?= (int) $row['returned'] ?></td>
                        <td data-label="Pending"><?= (int) $row['pending'] ?></td>
                        <td data-label="Total"><?= (int) $row['total'] ?></td>
                        <td data-label="Compliance"><strong><?= $pct ?>%</strong></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/../partials/footer.php'; ?>
