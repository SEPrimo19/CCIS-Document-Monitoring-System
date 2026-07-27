<?php
/**
 * @var string $appName
 * @var array{user_id:int,first_name:string,last_name:string,email:string,role_name:string} $user
 * @var array{period_id:int,school_year:string,semester:string,label:?string,start_date:?string,end_date:?string,is_active:int}|null $period
 * @var array{statusCounts:array{Pending:int,Submitted:int,Approved:int,'Returned-for-revision':int},total:int,complianceRate:int,overdueCount:int}|null $figures
 * @var int $awaitingCount submissions waiting on the Secretary's decision (FR-12)
 */
require __DIR__ . '/../partials/header.php';

$periodLabel = $period !== null
    ? ($period['label'] ?? ($period['school_year'] . ' — ' . $period['semester'] . ' Semester'))
    : null;
?>
<section class="dash-hero">
    <span class="badge">Secretary</span>
    <h1>Welcome, <?= htmlspecialchars($user['first_name']) ?></h1>
    <p class="sub">Signed in as <?= htmlspecialchars($user['email']) ?> &middot; <?= htmlspecialchars($user['role_name']) ?></p>
    <?php if ($periodLabel !== null): ?>
        <p class="dash-period">Active period: <?= htmlspecialchars($periodLabel) ?></p>
    <?php endif; ?>
</section>

<p class="dash-cta">
    <a href="<?= url('/admin/monitoring') ?>" class="btn-primary btn-inline">Open Monitoring Board &rarr;</a>
</p>

<?php if ($period === null): ?>
    <p class="alert alert-err" role="alert">
        No active academic period is set. Activate a period to see compliance figures (FR-18).
    </p>
<?php else: ?>
    <section class="cards">
        <article class="card">
            <h2>Pending</h2>
            <p class="status"><?= (int) $figures['statusCounts']['Pending'] ?></p>
            <p class="detail">Not yet submitted, active period.</p>
        </article>

        <article class="card">
            <h2>Submitted</h2>
            <p class="status"><?= (int) $figures['statusCounts']['Submitted'] ?></p>
            <p class="detail">Awaiting reviewer action.</p>
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
            <p class="detail"><?= (int) $figures['total'] ?> submissions this period.</p>
        </article>

        <article class="card<?= $figures['overdueCount'] > 0 ? ' err' : '' ?>">
            <h2>Overdue</h2>
            <p class="status"><?= (int) $figures['overdueCount'] ?></p>
            <p class="detail">Past deadline, not yet approved (FR-20).</p>
        </article>
    </section>

    <?php
    $sc = $figures['statusCounts'];
    $barTotal = (int) $figures['total'];
    $segments = [
        ['Pending',   (int) $sc['Pending'],               'seg-pending'],
        ['Submitted', (int) $sc['Submitted'],             'seg-submitted'],
        ['Approved',  (int) $sc['Approved'],              'seg-approved'],
        ['Returned',  (int) $sc['Returned-for-revision'], 'seg-returned'],
    ];
    ?>
    <?php if ($barTotal > 0): ?>
        <section class="status-dist">
            <h2 class="section-title">Status distribution</h2>
            <?php // Inline SVG: geometry via attributes, colour via CSS classes, so the ?>
            <?php // proportional bar renders under the strict CSP (no inline styles). ?>
            <svg class="status-bar" viewBox="0 0 100 6" preserveAspectRatio="none" role="img"
                 aria-label="Submission status distribution across <?= $barTotal ?> submissions this period.">
                <?php $x = 0.0; ?>
                <?php foreach ($segments as [$segLabel, $segCount, $segClass]): ?>
                    <?php if ($segCount > 0): ?>
                        <?php $segW = $segCount / $barTotal * 100; ?>
                        <rect class="<?= $segClass ?>" x="<?= round($x, 3) ?>" y="0" width="<?= round($segW, 3) ?>" height="6"><title><?= htmlspecialchars($segLabel . ': ' . $segCount) ?></title></rect>
                        <?php $x += $segW; ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            </svg>
            <ul class="status-legend">
                <?php foreach ($segments as [$segLabel, $segCount, $segClass]): ?>
                    <li class="legend-item">
                        <span class="legend-swatch <?= $segClass ?>" aria-hidden="true"></span>
                        <?= htmlspecialchars($segLabel) ?> &mdash; <strong><?= $segCount ?></strong>
                        <span class="legend-pct">(<?= round($segCount / $barTotal * 100) ?>%)</span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>
<?php endif; ?>

<section class="cards">
    <article class="card<?= $awaitingCount > 0 ? ' err' : '' ?>">
        <h2>Awaiting Review</h2>
        <p class="status"><?= (int) $awaitingCount ?></p>
        <p class="detail">Submitted documents waiting for your decision (FR-12, FR-13).</p>
        <p class="status muted"><a href="<?= url('/reviewer/queue') ?>" class="card-link">Open review queue &rarr;</a></p>
    </article>

    <article class="card">
        <h2>Faculty Compliance</h2>
        <p class="detail">Per-faculty summary of submitted, approved, returned, and pending requirements (FR-16).</p>
        <p class="status muted"><a href="<?= url('/reviewer/compliance') ?>" class="card-link">View faculty compliance &rarr;</a></p>
    </article>

    <article class="card">
        <h2>User Accounts</h2>
        <p class="detail">Create, edit, deactivate accounts, and assign roles (FR-26).</p>
        <p class="status muted"><a href="<?= url('/admin/users') ?>" class="card-link">Manage users &rarr;</a></p>
    </article>

    <article class="card">
        <h2>Document Types</h2>
        <p class="detail">Add, edit, and deactivate document types (FR-27).</p>
        <p class="status muted"><a href="<?= url('/admin/document-types') ?>" class="card-link">Manage document types &rarr;</a></p>
    </article>

    <article class="card">
        <h2>Requirements &amp; Periods</h2>
        <p class="detail">Define requirements, deadlines, and academic periods (FR-28, FR-29).</p>
        <p class="status muted"><a href="<?= url('/admin/requirements') ?>" class="card-link">Manage requirements &rarr;</a></p>
    </article>

    <article class="card">
        <h2>Monitoring Board</h2>
        <p class="detail">Faculty &times; requirement compliance matrix and submission search (FR-17, FR-19, FR-20).</p>
        <p class="status muted"><a href="<?= url('/admin/monitoring') ?>" class="card-link">Open monitoring board &rarr;</a></p>
    </article>

    <article class="card">
        <h2>Reports</h2>
        <p class="detail">Compliance and status reports, exportable to CSV or Print / Save as PDF (FR-24, FR-25).</p>
        <p class="status muted"><a href="<?= url('/admin/reports') ?>" class="card-link">Open reports &rarr;</a></p>
    </article>

    <article class="card">
        <h2>Audit Log</h2>
        <p class="detail">Admin-only record of key actions across the system (FR-30, FR-31).</p>
        <p class="status muted"><a href="<?= url('/admin/audit-log') ?>" class="card-link">Open audit log &rarr;</a></p>
    </article>
</section>
<?php require __DIR__ . '/../partials/footer.php'; ?>
