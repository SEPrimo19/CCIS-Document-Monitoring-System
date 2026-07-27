<?php
/**
 * @var string $appName
 * @var list<array{period_id:int,school_year:string,semester:string,label:?string,is_active:int,requirement_count:int,submission_count:int}> $periods
 * @var array{period_id:int,school_year:string,semester:string,label:?string,start_date:?string,end_date:?string,is_active:int}|null $active
 */
require __DIR__ . '/../partials/header.php';

$activeLabel = $active !== null
    ? ($active['label'] ?? ('AY ' . $active['school_year'] . ', ' . $active['semester'] . ' Semester'))
    : null;
?>
<section class="page-head">
    <div>
        <span class="badge">Archive</span>
        <h1>Archive</h1>
        <p class="sub">Browse documents from past academic periods, read-only (FR-33).</p>
    </div>
</section>

<?php if ($activeLabel !== null): ?>
    <p class="muted-note">
        The current period, <strong><?= htmlspecialchars($activeLabel) ?></strong>, is not listed here &mdash;
        it moves to the archive once the administrator activates a new one. Nothing is ever deleted.
    </p>
<?php endif; ?>

<?php if ($periods === []): ?>
    <p class="muted-note">
        No past academic periods yet. Once a period is closed and a new one activated, its requirements,
        submissions, and decisions appear here.
    </p>
<?php else: ?>
    <div class="table-wrap">
        <table class="table table--stack">
            <thead>
                <tr>
                    <th>Academic period</th>
                    <th>Requirements</th>
                    <th>Submissions</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($periods as $period): ?>
                    <?php $name = $period['label'] ?? ('AY ' . $period['school_year'] . ', ' . $period['semester'] . ' Semester'); ?>
                    <tr>
                        <td data-label="Academic period">
                            <strong><?= htmlspecialchars($name) ?></strong>
                            <p class="muted-note"><?= htmlspecialchars($period['school_year']) ?> &middot; <?= htmlspecialchars($period['semester']) ?></p>
                        </td>
                        <td data-label="Requirements"><?= (int) $period['requirement_count'] ?></td>
                        <td data-label="Submissions"><?= (int) $period['submission_count'] ?></td>
                        <td class="table-actions" data-label="Action">
                            <a href="<?= url('/archive/' . $period['period_id']) ?>" class="btn-sm btn-secondary">Open</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/../partials/footer.php'; ?>
