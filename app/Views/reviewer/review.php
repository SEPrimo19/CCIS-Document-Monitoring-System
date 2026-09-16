<?php
/**
 * @var string $appName
 * @var array{submission_id:int,status:string,current_version:int,submitted_at:?string,updated_at:string,title:string,description:?string,deadline:?string,doc_type_name:string,faculty_name:string,file_id:?int,file_name:?string} $submission
 * @var list<array{review_id:int,decision:string,comments:?string,reviewed_at:string,reviewer_name:string}> $history
 * @var string $decision
 * @var string $comments
 * @var array<string,string> $errors
 * @var string $csrf
 */
require __DIR__ . '/../partials/header.php';

$statusPillClass = [
    'Pending'   => 'status-pill-pending',
    'Submitted' => 'status-pill-submitted',
    'Approved'  => 'status-pill-approved',
    'Revised'   => 'status-pill-revised',
];
$pillClass = $statusPillClass[$submission['status']] ?? 'status-pill-pending';
$canDecide = $submission['status'] === 'Submitted';
?>
<section class="page-head">
    <div>
        <span class="badge">Secretary</span>
        <h1><?= htmlspecialchars($submission['title']) ?></h1>
        <p class="sub">Review this submission and record a decision (FR-13, FR-14).</p>
    </div>
    <a href="<?= url('/reviewer/queue') ?>" class="btn-sm btn-secondary">Back to queue</a>
</section>

<section class="review-meta">
    <dl class="meta-list">
        <div><dt>Faculty</dt><dd><?= htmlspecialchars($submission['faculty_name']) ?></dd></div>
        <div><dt>Document type</dt><dd><?= htmlspecialchars($submission['doc_type_name']) ?></dd></div>
        <div><dt>Description</dt><dd><?= $submission['description'] !== null ? htmlspecialchars($submission['description']) : '—' ?></dd></div>
        <div>
            <dt>Deadline</dt>
            <dd><?= $submission['deadline'] !== null ? htmlspecialchars(date('M j, Y', strtotime($submission['deadline']))) : '—' ?></dd>
        </div>
        <div><dt>Status</dt><dd><span class="status-pill <?= $pillClass ?>"><?= htmlspecialchars($submission['status']) ?></span></dd></div>
        <div><dt>Version</dt><dd>v<?= (int) $submission['current_version'] ?></dd></div>
    </dl>

    <?php if ($submission['file_id'] !== null): ?>
        <a href="<?= url('/documents/' . $submission['file_id'] . '/download') ?>" class="btn-sm btn-primary-sm">Download document</a>
    <?php else: ?>
        <p class="muted-note">No file on record for this version.</p>
    <?php endif; ?>
</section>

<?php if ($history !== []): ?>
    <section class="review-history">
        <h2>Review history</h2>
        <ul class="history-list">
            <?php foreach ($history as $entry): ?>
                <?php $entryPill = $entry['decision'] === 'Approved' ? 'status-pill-approved' : 'status-pill-revised'; ?>
                <li>
                    <span class="status-pill <?= $entryPill ?>"><?= htmlspecialchars($entry['decision']) ?></span>
                    <span class="history-meta">
                        <?= htmlspecialchars($entry['reviewer_name']) ?> &middot;
                        <?= htmlspecialchars(date('M j, Y g:ia', strtotime($entry['reviewed_at']))) ?>
                    </span>
                    <?php if ($entry['comments'] !== null && $entry['comments'] !== ''): ?>
                        <p class="history-comment"><?= htmlspecialchars($entry['comments']) ?></p>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<section class="decision-form-wrap">
    <?php if ($canDecide): ?>
        <form method="post" action="<?= url('/reviewer/submissions/' . $submission['submission_id'] . '/review') ?>" class="decision-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="current_version" value="<?= (int) $submission['current_version'] ?>">

            <div class="field">
                <label>Decision</label>
                <label class="radio-label">
                    <input type="radio" name="decision" value="Approved" <?= $decision === 'Approved' ? 'checked' : '' ?>>
                    Approve
                </label>
                <label class="radio-label">
                    <input type="radio" name="decision" value="Revised" <?= $decision === 'Revised' ? 'checked' : '' ?>>
                    Return for revision
                </label>
                <?php if (!empty($errors['decision'])): ?>
                    <p class="field-err"><?= htmlspecialchars($errors['decision']) ?></p>
                <?php endif; ?>
            </div>

            <div class="field">
                <label for="comments">Comments</label>
                <textarea id="comments" name="comments" rows="4" maxlength="65535"><?= htmlspecialchars($comments) ?></textarea>
                <p class="field-help">Comments required when returning.</p>
                <?php if (!empty($errors['comments'])): ?>
                    <p class="field-err"><?= htmlspecialchars($errors['comments']) ?></p>
                <?php endif; ?>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn-primary btn-inline">Submit decision</button>
            </div>
        </form>
    <?php else: ?>
        <p class="alert <?= $submission['status'] === 'Approved' ? 'alert-ok' : 'alert-err' ?>" role="status">
            Already <?= htmlspecialchars($submission['status']) ?>.
        </p>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../partials/footer.php'; ?>
