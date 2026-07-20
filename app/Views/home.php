<?php
/**
 * @var string $appName
 * @var string $phpVer
 * @var string $dbStatus  'connected' | 'error'
 * @var string $dbDetail
 */
$dbOk = $dbStatus === 'connected';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($appName) ?></title>
    <link rel="stylesheet" href="<?= asset('css/style.css') ?>">
</head>
<body>
    <main class="wrap">
        <header class="hero">
            <span class="badge">CCIS-DMS</span>
            <h1><?= htmlspecialchars($appName) ?></h1>
            <p class="sub">Northwest Samar State University · College of Computing and Information Sciences</p>
        </header>

        <section class="cards">
            <article class="card ok">
                <h2>Application</h2>
                <p class="status">Running</p>
                <p class="detail">Front controller, router, and views are wired up.</p>
            </article>

            <article class="card ok">
                <h2>PHP</h2>
                <p class="status"><?= htmlspecialchars($phpVer) ?></p>
                <p class="detail">Runtime detected.</p>
            </article>

            <article class="card <?= $dbOk ? 'ok' : 'err' ?>">
                <h2>MySQL</h2>
                <p class="status"><?= $dbOk ? 'Connected' : 'Not connected' ?></p>
                <p class="detail"><?= htmlspecialchars($dbDetail) ?></p>
            </article>
        </section>

        <footer class="foot">
            <p>Admin-only health &amp; diagnostics page — application, PHP runtime, and database connectivity at a glance.</p>
        </footer>
    </main>
    <script src="<?= asset('js/app.js') ?>"></script>
</body>
</html>
