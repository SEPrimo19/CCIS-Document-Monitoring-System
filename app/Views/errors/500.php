<?php
/**
 * Rendered directly by the global exception handler (public/index.php) when
 * an uncaught throwable reaches the front controller. Mirrors 403's markup.
 *
 * @var string $appName
 * @var string|null $debugMessage  Exception message; only set outside production.
 */
require __DIR__ . '/../partials/header.php';
?>
<section class="error-card">
    <span class="badge err">500</span>
    <h1>Something went wrong</h1>
    <p class="sub">An unexpected error occurred. It has been logged; please try again.</p>
    <?php if (!empty($debugMessage)): ?>
        <p class="alert alert-err" role="alert"><?= htmlspecialchars($debugMessage) ?></p>
    <?php endif; ?>
    <p><a href="<?= url('/dashboard') ?>">Return to your dashboard</a></p>
</section>
<?php require __DIR__ . '/../partials/footer.php'; ?>
