<?php
/**
 * @var string $appName
 * @var array{user_id:int,first_name:string,last_name:string,email:string,role_name:string} $user
 * @var array{Pending:int,Submitted:int,Approved:int,'Returned-for-revision':int} $counts
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
        <h2>Pending</h2>
        <p class="status"><?= (int) $counts['Pending'] ?></p>
        <p class="detail">Not yet uploaded for the active period.</p>
    </article>

    <article class="card">
        <h2>Submitted</h2>
        <p class="status"><?= (int) $counts['Submitted'] ?></p>
        <p class="detail">Awaiting reviewer action.</p>
    </article>

    <article class="card ok">
        <h2>Approved</h2>
        <p class="status"><?= (int) $counts['Approved'] ?></p>
        <p class="detail">Accepted by a reviewer.</p>
    </article>

    <article class="card err">
        <h2>Returned</h2>
        <p class="status"><?= (int) $counts['Returned-for-revision'] ?></p>
        <p class="detail">Needs revision and resubmission.</p>
    </article>
</section>

<section class="cards">
    <article class="card">
        <h2>My Requirements</h2>
        <p class="detail">Checklist of submitted vs. pending/missing requirements for the active period, with deadlines and upload (FR-6, FR-7, FR-8).</p>
        <p class="status muted"><a href="<?= url('/faculty/requirements') ?>" class="card-link">View checklist &rarr;</a></p>
    </article>

    <article class="card">
        <h2>My Submissions</h2>
        <p class="detail">Status and last-updated date for each submission (FR-9); resubmit returned items (FR-10).</p>
        <p class="status muted">Coming in Phase 4</p>
    </article>
</section>
<?php require __DIR__ . '/../partials/footer.php'; ?>
