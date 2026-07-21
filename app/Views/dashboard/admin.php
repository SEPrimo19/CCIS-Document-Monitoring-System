<?php
/**
 * @var string $appName
 * @var array{user_id:int,first_name:string,last_name:string,email:string,role_name:string} $user
 * @var array{period_id:int,school_year:string,semester:string,label:?string,start_date:?string,end_date:?string,is_active:int}|null $period
 * @var array{statusCounts:array{Pending:int,Submitted:int,Approved:int,'Returned-for-revision':int},total:int,complianceRate:int,overdueCount:int}|null $figures
 */
require __DIR__ . '/../partials/header.php';
?>
<section class="dash-hero">
    <span class="badge">Administrator</span>
    <h1>Welcome, <?= htmlspecialchars($user['first_name']) ?></h1>
    <p class="sub">Signed in as <?= htmlspecialchars($user['email']) ?> &middot; <?= htmlspecialchars($user['role_name']) ?></p>
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
<?php endif; ?>

<section class="cards">
    <article class="card">
        <h2>User Accounts</h2>
        <p class="detail">Create, edit, deactivate accounts, and assign roles (FR-26).</p>
        <p class="status muted">Coming in Phase 4</p>
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
        <p class="detail">Compliance and status reports, exportable to PDF (FR-24, FR-25).</p>
        <p class="status muted">Coming in Phase 4</p>
    </article>

    <article class="card">
        <h2>Audit Log</h2>
        <p class="detail">Admin-only record of key actions across the system (FR-30, FR-31).</p>
        <p class="status muted">Coming in Phase 4</p>
    </article>
</section>
<?php require __DIR__ . '/../partials/footer.php'; ?>
