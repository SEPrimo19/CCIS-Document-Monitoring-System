<?php
/**
 * Rendered directly by App\Core\Router when no route matches the request.
 *
 * @var string $appName
 */
require __DIR__ . '/../partials/header.php';
?>
<section class="error-card">
    <span class="badge err">404</span>
    <h1>Page not found</h1>
    <p class="sub">The page you're looking for doesn't exist or may have moved.</p>
    <p><a href="<?= url('/dashboard') ?>">Return to your dashboard</a></p>
</section>
<?php require __DIR__ . '/../partials/footer.php'; ?>
