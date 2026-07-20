<?php
/**
 * Rendered directly by App\Core\Guard (not via Controller::view()) when a
 * signed-in user's role does not permit the requested page.
 *
 * @var string $appName
 */
require __DIR__ . '/../partials/header.php';
?>
<section class="error-card">
    <span class="badge err">403</span>
    <h1>Access denied</h1>
    <p class="sub">Your account role does not have permission to view this page.</p>
    <p><a href="/dashboard">Return to your dashboard</a></p>
</section>
<?php require __DIR__ . '/../partials/footer.php'; ?>
