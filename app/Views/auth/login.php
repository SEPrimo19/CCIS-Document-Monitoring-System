<?php
/**
 * @var string $appName
 * @var string|null $error
 * @var string $email
 * @var string $csrf
 */
require __DIR__ . '/../partials/header.php';
?>
<section class="auth-card">
    <span class="badge">CCIS-DMS</span>
    <h1>Sign in</h1>
    <p class="sub">College of Computing and Information Sciences &middot; NwSSU</p>

    <?php if (!empty($error)): ?>
        <p class="alert alert-err" role="alert"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="post" action="/login" class="auth-form" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

        <div class="field">
            <label for="email">Email</label>
            <input
                type="email"
                id="email"
                name="email"
                value="<?= htmlspecialchars($email) ?>"
                required
                autofocus
                autocomplete="username"
            >
        </div>

        <div class="field">
            <label for="password">Password</label>
            <input
                type="password"
                id="password"
                name="password"
                required
                autocomplete="current-password"
            >
        </div>

        <button type="submit" class="btn-primary">Sign in</button>
    </form>
</section>
<?php require __DIR__ . '/../partials/footer.php'; ?>
