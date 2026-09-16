<?php
/**
 * @var string $appName
 * @var array{user_id:int,first_name:string,last_name:string,email:string,role_name:string} $user
 * @var array{period_id:int,school_year:string,semester:string,label:?string,start_date:?string,end_date:?string,is_active:int}|null $period
 * @var array{Pending:int,Submitted:int,Approved:int,Revised:int} $counts
 * @var array{month:string,label:string,prev:string,next:string,start:string,end:string,weeks:list<list<?string>>} $calendarWindow FR-39
 * @var array<string,array{count:int,overdue:int,items:list<array{title:string,doc_type_name:string,is_overdue:int}>}> $calendarDays FR-39
 * @var string $calendarToday FR-39
 */
require __DIR__ . '/../partials/header.php';

$periodLabel = $period !== null
    ? ($period['label'] ?? ($period['school_year'] . ' — ' . $period['semester'] . ' Semester'))
    : null;
?>
<section class="dash-hero">
    <span class="badge">Faculty</span>
    <h1>Welcome, <?= htmlspecialchars($user['first_name']) ?></h1>
    <p class="sub">Signed in as <?= htmlspecialchars($user['email']) ?> &middot; <?= htmlspecialchars($user['role_name']) ?></p>
    <?php if ($periodLabel !== null): ?>
        <p class="dash-period">Active period: <?= htmlspecialchars($periodLabel) ?></p>
    <?php endif; ?>
</section>

<?php if ($period === null): ?>
    <p class="alert alert-err" role="alert">
        No active academic period is set. Your requirements will appear here once the administrator activates one.
    </p>
<?php else: ?>
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
            <h2>Revised</h2>
            <p class="status"><?= (int) $counts['Revised'] ?></p>
            <p class="detail">Needs revision and resubmission.</p>
        </article>
    </section>
<?php endif; ?>

<?php
// FR-39: deadline calendar, scoped by FacultyController to this faculty
// member's own submissions. A marked day opens the checklist those deadlines
// belong to, which is what the client asked a day click to do.
$calendarDashboardPath = '/faculty/dashboard';
$calendarTargetPath = '/faculty/requirements';
$calendarTargetLabel = 'My Requirements';
$calendarHasPeriod = $period !== null;
require __DIR__ . '/../partials/deadline-calendar.php';
?>

<section class="cards">
    <article class="card">
        <h2>My Requirements</h2>
        <p class="detail">Checklist of submitted vs. pending requirements for the active period, with deadlines, upload, status, and resubmission of revised items (FR-6 to FR-10).</p>
        <p class="status muted"><a href="<?= url('/faculty/requirements') ?>" class="card-link">View checklist &rarr;</a></p>
    </article>

    <article class="card">
        <h2>Notifications</h2>
        <p class="detail">Approvals, returns, and reminders raised on your submissions (FR-21, FR-22).</p>
        <p class="status muted"><a href="<?= url('/notifications') ?>" class="card-link">View notifications &rarr;</a></p>
    </article>
</section>
<?php require __DIR__ . '/../partials/footer.php'; ?>
