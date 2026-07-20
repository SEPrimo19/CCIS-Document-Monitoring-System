<?php
/**
 * @var string $appName
 * @var array{user_id:int,first_name:string,last_name:string,email:string,role_name:string} $user
 */
require __DIR__ . '/../partials/header.php';
?>
<section class="dash-hero">
    <span class="badge">Faculty</span>
    <h1>Welcome, <?= htmlspecialchars($user['first_name']) ?></h1>
    <p class="sub">Signed in as <?= htmlspecialchars($user['email']) ?> &middot; <?= htmlspecialchars($user['role_name']) ?></p>
</section>

<section class="cards">
    <article class="card">
        <h2>My Requirements</h2>
        <p class="detail">Checklist of submitted vs. pending/missing requirements for the active period, with deadlines (FR-6).</p>
        <p class="status muted">Coming in Phase 4</p>
    </article>

    <article class="card">
        <h2>Upload Document</h2>
        <p class="detail">Submit a signed document against a requirement (FR-7, FR-8).</p>
        <p class="status muted">Coming in Phase 4</p>
    </article>

    <article class="card">
        <h2>My Submissions</h2>
        <p class="detail">Status and last-updated date for each submission (FR-9); resubmit returned items (FR-10).</p>
        <p class="status muted">Coming in Phase 4</p>
    </article>
</section>
<?php require __DIR__ . '/../partials/footer.php'; ?>
