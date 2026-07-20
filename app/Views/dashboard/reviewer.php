<?php
/**
 * @var string $appName
 * @var array{user_id:int,first_name:string,last_name:string,email:string,role_name:string} $user
 */
require __DIR__ . '/../partials/header.php';
?>
<section class="dash-hero">
    <span class="badge">Reviewer/Approver</span>
    <h1>Welcome, <?= htmlspecialchars($user['first_name']) ?></h1>
    <p class="sub">Signed in as <?= htmlspecialchars($user['email']) ?> &middot; <?= htmlspecialchars($user['role_name']) ?></p>
</section>

<section class="cards">
    <article class="card">
        <h2>Review Queue</h2>
        <p class="detail">Submitted documents awaiting a decision, filterable by document type and period (FR-12).</p>
        <p class="status muted">Coming in Phase 4</p>
    </article>

    <article class="card">
        <h2>Decisions</h2>
        <p class="detail">Approve or return for revision, with required comments on return (FR-13, FR-14).</p>
        <p class="status muted">Coming in Phase 4</p>
    </article>

    <article class="card">
        <h2>Faculty Compliance</h2>
        <p class="detail">Per-faculty summary of submitted, approved, returned, and pending requirements (FR-16).</p>
        <p class="status muted">Coming in Phase 4</p>
    </article>
</section>
<?php require __DIR__ . '/../partials/footer.php'; ?>
