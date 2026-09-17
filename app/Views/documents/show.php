<?php
/**
 * In-app document viewer (FR-41). Reached from every list that shows a
 * document, so the reader never has to download one to read it.
 *
 * @var string $appName
 * @var array{file_id:int,submission_id:int,file_name:string,mime_type:string,file_size:int,version_no:int,uploaded_at:string,faculty_name:string,status:string,title:string,doc_type_name:string,deadline:?string,period_label:?string,school_year:string,semester:string} $document
 * @var string $ext
 * @var string $mode          pdf | html | text | download-only
 * @var string|null $html    rendered .docx markup, built entirely by DocxHtml
 * @var int $pageCount        pages found, 1 when the document records none
 * @var string|null $text
 * @var string|null $textStatus
 * @var bool $truncated
 * @var bool $missing
 * @var bool $isSecretary
 */
require __DIR__ . '/../partials/header.php';

$periodLabel = ($document['period_label'] ?? '') !== ''
    ? $document['period_label']
    : 'AY ' . $document['school_year'] . ', ' . $document['semester'] . ' Semester';

$statusPill = [
    'Pending'   => 'status-pill-pending',
    'Submitted' => 'status-pill-submitted',
    'Approved'  => 'status-pill-approved',
    'Revised'   => 'status-pill-revised',
][$document['status']] ?? 'status-pill-pending';
?>
<?php // data-doc-body marks what the modal lifts out of this page. The
     // page stays a real, linkable screen — it is what a no-script
     // browser gets, and what "open in a new tab" lands on. ?>
<div data-doc-body>
<section class="page-head">
    <div>
        <span class="badge"><?= $isSecretary ? 'Secretary' : 'Faculty' ?></span>
        <h1><?= htmlspecialchars($document['file_name']) ?></h1>
        <p class="sub">
            <?= htmlspecialchars($document['title']) ?> &middot;
            <?= htmlspecialchars($document['doc_type_name']) ?> &middot;
            v<?= (int) $document['version_no'] ?>
        </p>
    </div>
    <a href="<?= url('/documents/' . $document['file_id'] . '/download') ?>" class="btn-primary btn-inline">Download</a>
</section>

<section class="form-card">
    <dl class="meta-list">
        <div><dt>Faculty</dt><dd><?= htmlspecialchars($document['faculty_name']) ?></dd></div>
        <div><dt>Status</dt><dd><span class="status-pill <?= $statusPill ?>"><?= htmlspecialchars($document['status']) ?></span></dd></div>
        <div><dt>Period</dt><dd><?= htmlspecialchars($periodLabel) ?></dd></div>
        <div><dt>Uploaded</dt><dd><?= htmlspecialchars(date('M j, Y g:ia', strtotime($document['uploaded_at']))) ?></dd></div>
        <div><dt>Size</dt><dd><?= number_format($document['file_size'] / 1024, 0) ?> KB</dd></div>
        <div><dt>Deadline</dt><dd><?= $document['deadline'] !== null ? htmlspecialchars(date('M j, Y', strtotime($document['deadline']))) : '&mdash;' ?></dd></div>
    </dl>

    <?php // The Secretary reaches the decision form from here, so viewing and
          // deciding are one trip rather than two. Only for a document actually
          // awaiting a decision — the review route refuses anything else. ?>
    <?php if ($isSecretary && $document['status'] === 'Submitted'): ?>
        <p class="doc-actions">
            <a href="<?= url('/reviewer/submissions/' . $document['submission_id'] . '/review') ?>" class="btn-sm btn-primary-sm">Review this submission &rarr;</a>
        </p>
    <?php endif; ?>
</section>

<section class="form-card">
    <h2>Document</h2>

    <?php if ($missing): ?>
        <p class="alert alert-err" role="alert">
            This file is recorded in the system but is missing from storage. Ask the faculty member to upload it again.
        </p>

    <?php elseif ($mode === 'pdf'): ?>
        <div class="doc-viewer">
            <iframe class="doc-frame"
                    src="<?= url('/documents/' . $document['file_id'] . '/view') ?>"
                    title="<?= htmlspecialchars($document['file_name']) ?>"
                    loading="lazy"></iframe>
        </div>
        <p class="muted-note">
            <a href="<?= url('/documents/' . $document['file_id'] . '/view') ?>" target="_blank" rel="noopener">Open in a new tab</a>
            if the preview is hard to read here.
        </p>

    <?php elseif ($mode === 'html'): ?>
        <?php // Rendered from the document's own XML. Every tag in $html is
              // written by DocxHtml and the document's text is escaped on the
              // way in, so nothing inside an uploaded file can become markup
              // here — which is why this is the one place in the application
              // that echoes without escaping. ?>
        <p class="muted-note doc-note">
            <?php if ($pageCount > 1): ?>
                <strong><?= (int) $pageCount ?> pages.</strong>
                Split where Word recorded its page breaks, so these match the pages the author saw.
            <?php else: ?>
                <?php // A .docx does not store page boundaries; Word writes a hint at each
                      // break only when it saves after laying the document out. A file
                      // recovered from an autosave has none, and there is nothing to infer
                      // them from — so say so rather than invent a page count. ?>
                <strong>Shown as one continuous page.</strong>
                This file does not record where its pages break, so they cannot be shown separately.
            <?php endif; ?>
            Headers, footers and exact spacing are not reproduced &mdash;
            <a href="<?= url('/documents/' . $document['file_id'] . '/download') ?>">download the file</a>
            to see it exactly as written.
            <?php if ($truncated): ?> This document is long, so only the beginning is shown.<?php endif; ?>
        </p>
        <div class="doc-render" tabindex="0" role="region" aria-label="Contents of <?= htmlspecialchars($document['file_name']) ?>"><?= $html ?></div>

    <?php elseif ($mode === 'text'): ?>
        <?php // Stated plainly, because it is not a rendering: the reader needs
              // to know the layout they are NOT seeing before they judge the
              // document on it. ?>
        <p class="alert alert-ok" role="status">
            <strong>Text preview.</strong> Word documents cannot be displayed by a browser, so this is the
            document&rsquo;s text only &mdash; tables are flattened and images, headers and formatting are not shown.
            Download the file to see it exactly as written.
            <?php if ($truncated): ?>
                This document is long, so only the beginning is shown here.
            <?php endif; ?>
        </p>
        <div class="doc-text" tabindex="0" role="region" aria-label="Extracted text of <?= htmlspecialchars($document['file_name']) ?>"><?= htmlspecialchars($text ?? '') ?></div>

    <?php else: ?>
        <p class="alert alert-err" role="status">
            <?php if ($ext === 'doc'): ?>
                <strong>Preview not available.</strong> The older Word format (<code>.doc</code>) cannot be read
                without converting it first. Download the file to read it.
            <?php elseif ($textStatus === \App\Core\DocxText::TOO_LARGE): ?>
                <strong>Preview not available.</strong> This document is too large to preview safely. Download it to read it.
            <?php elseif ($textStatus === \App\Core\DocxText::NO_TEXT): ?>
                <strong>No text to preview.</strong> This document appears to contain only images or scanned pages.
                Download it to read it.
            <?php else: ?>
                <strong>Preview not available</strong> for <code><?= htmlspecialchars(strtoupper($ext)) ?></code> files.
                Download the file to read it.
            <?php endif; ?>
        </p>
    <?php endif; ?>
</section>
</div>

<?php require __DIR__ . '/../partials/footer.php'; ?>
