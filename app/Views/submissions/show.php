<?php
/**
 * Read-only document detail (FR-11). Deliberately has no decision form — the
 * reviewer's decision screen stays the single place a status can change.
 *
 * @var string $appName
 * @var array{submission_id:int,status:string,current_version:int,submitted_at:?string,updated_at:string,title:string,description:?string,deadline:?string,doc_type_name:string,faculty_name:string,file_id:?int,file_name:?string,faculty_id:int} $submission
 * @var array{period_id:int,school_year:string,semester:string,label:?string,is_active:int}|null $period
 * @var list<array{file_id:int,file_name:string,mime_type:string,file_size:int,version_no:int,uploaded_at:string,uploaded_by_name:string}> $versions
 * @var list<array{review_id:int,decision:string,comments:?string,reviewed_at:string,reviewer_name:string}> $history
 * @var bool $isOwner
 */
require __DIR__ . '/../partials/header.php';

$statusPillClass = [
    'Pending'   => 'status-pill-pending',
    'Submitted' => 'status-pill-submitted',
    'Approved'  => 'status-pill-approved',
    'Revised'   => 'status-pill-revised',
];
$pillClass = $statusPillClass[$submission['status']] ?? 'status-pill-pending';

$periodLabel = $period !== null
    ? ($period['label'] ?? ('AY ' . $period['school_year'] . ', ' . $period['semester'] . ' Semester'))
    : null;
$isArchived = $period !== null && (int) $period['is_active'] === 0;

// Back-link by role: faculty return to their own checklist, the Secretary to
// the monitoring board. Both destinations are reachable by the role that gets
// them, so this link can never land the user on a 403.
$backUrl = $isOwner ? url('/faculty/requirements') : url('/admin/monitoring');
$backText = $isOwner ? 'Back to my requirements' : 'Back to monitoring';

/** Human-readable file size — the raw byte count means nothing to a reader. */
$formatSize = static function (int $bytes): string {
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 0) . ' KB';
    }

    return $bytes . ' B';
};
?>
<section class="page-head">
    <div>
        <span class="badge">Document</span>
        <h1><?= htmlspecialchars($submission['title']) ?></h1>
        <p class="sub">
            Full record: status, reviewer comments, and every uploaded version (FR-11).
            <?php if ($periodLabel !== null): ?>
                &middot; <?= htmlspecialchars($periodLabel) ?>
            <?php endif; ?>
        </p>
    </div>
    <a href="<?= $backUrl ?>" class="btn-sm btn-secondary"><?= htmlspecialchars($backText) ?></a>
</section>

<?php if ($isArchived): ?>
    <p class="alert alert-ok" role="status">
        This document belongs to a closed academic period and is shown for reference only.
    </p>
<?php endif; ?>

<section class="review-meta">
    <dl class="meta-list">
        <div><dt>Faculty</dt><dd><?= htmlspecialchars($submission['faculty_name']) ?></dd></div>
        <div><dt>Document type</dt><dd><?= htmlspecialchars($submission['doc_type_name']) ?></dd></div>
        <div><dt>Description</dt><dd><?= $submission['description'] !== null ? htmlspecialchars($submission['description']) : '&mdash;' ?></dd></div>
        <div>
            <dt>Deadline</dt>
            <dd><?= $submission['deadline'] !== null ? htmlspecialchars(date('M j, Y', strtotime($submission['deadline']))) : '&mdash;' ?></dd>
        </div>
        <div><dt>Status</dt><dd><span class="status-pill <?= $pillClass ?>"><?= htmlspecialchars($submission['status']) ?></span></dd></div>
        <div><dt>Current version</dt><dd><?= (int) $submission['current_version'] > 0 ? 'v' . (int) $submission['current_version'] : 'Not yet submitted' ?></dd></div>
        <div>
            <dt>First submitted</dt>
            <dd><?= $submission['submitted_at'] !== null ? htmlspecialchars(date('M j, Y g:ia', strtotime($submission['submitted_at']))) : '&mdash;' ?></dd>
        </div>
        <div><dt>Last updated</dt><dd><?= htmlspecialchars(date('M j, Y g:ia', strtotime($submission['updated_at']))) ?></dd></div>
    </dl>
</section>

<h2 class="section-title">Version history</h2>
<?php if ($versions === []): ?>
    <p class="muted-note">No file has been uploaded for this requirement yet.</p>
<?php else: ?>
    <div class="table-wrap">
        <table class="table table--stack">
            <thead>
                <tr>
                    <th>Version</th>
                    <th>File</th>
                    <th>Size</th>
                    <th>Uploaded by</th>
                    <th>Uploaded</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($versions as $version): ?>
                    <?php $isCurrent = (int) $version['version_no'] === (int) $submission['current_version']; ?>
                    <tr>
                        <td data-label="Version">
                            v<?= (int) $version['version_no'] ?>
                            <?php if ($isCurrent): ?><span class="status-pill status-pill-active">Current</span><?php endif; ?>
                        </td>
                        <td data-label="File"><?= htmlspecialchars($version['file_name']) ?></td>
                        <td data-label="Size"><?= htmlspecialchars($formatSize((int) $version['file_size'])) ?></td>
                        <td data-label="Uploaded by"><?= htmlspecialchars($version['uploaded_by_name']) ?></td>
                        <td data-label="Uploaded"><?= htmlspecialchars(date('M j, Y g:ia', strtotime($version['uploaded_at']))) ?></td>
                        <td class="table-actions" data-label="Action">
                            <a href="<?= url('/documents/' . $version['file_id']) ?>" class="btn-sm btn-primary-sm" data-doc-view>View</a>
                                <a href="<?= url('/documents/' . $version['file_id'] . '/download') ?>" class="btn-sm btn-secondary">Download</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<h2 class="section-title">Status history</h2>
<?php if ($history === []): ?>
    <p class="muted-note">No review decision has been recorded yet.</p>
<?php else: ?>
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
<?php endif; ?>
<?php require __DIR__ . '/../partials/footer.php'; ?>
