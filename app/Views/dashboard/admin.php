<?php
/**
 * @var string $appName
 * @var array{user_id:int,first_name:string,last_name:string,email:string,role_name:string} $user
 */
require __DIR__ . '/../partials/header.php';
?>
<section class="dash-hero">
    <span class="badge">Administrator</span>
    <h1>Welcome, <?= htmlspecialchars($user['first_name']) ?></h1>
    <p class="sub">Signed in as <?= htmlspecialchars($user['email']) ?> &middot; <?= htmlspecialchars($user['role_name']) ?></p>
</section>

<section class="cards">
    <article class="card">
        <h2>User Accounts</h2>
        <p class="detail">Create, edit, deactivate accounts, and assign roles (FR-26).</p>
        <p class="status muted">Coming in Phase 4</p>
    </article>

    <article class="card">
        <h2>Document Types</h2>
        <p class="detail">Add, edit, and deactivate document types (FR-27).</p>
        <p class="status muted">Coming in Phase 4</p>
    </article>

    <article class="card">
        <h2>Requirements &amp; Periods</h2>
        <p class="detail">Define requirements, deadlines, and academic periods (FR-28, FR-29).</p>
        <p class="status muted">Coming in Phase 4</p>
    </article>

    <article class="card">
        <h2>Monitoring Board</h2>
        <p class="detail">Faculty &times; document-type compliance matrix (FR-17).</p>
        <p class="status muted">Coming in Phase 4</p>
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
