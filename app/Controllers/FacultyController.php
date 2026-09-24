<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Guard;
use App\Models\AcademicPeriod;
use App\Models\AuditLog;
use App\Models\DeadlineCalendar;
use App\Models\DocumentFile;
use App\Models\Notification;
use App\Models\Submission;
use App\Models\User;
use finfo;
use Throwable;
use ZipArchive;

/**
 * Faculty landing (including the deadline calendar, FR-39) + "My Requirements"
 * checklist and upload (FR-6, FR-7, FR-8). My Submissions / document detail
 * are built out later in Phase 4.
 */
final class FacultyController extends Controller
{
    private const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

    /**
     * Extension => allowed detected MIME types. finfo is only a PRE-FILTER
     * here, not authoritative: .docx is a zip archive (so genuine files can
     * sniff as either the full wordprocessingml type or plain `application/zip`)
     * and libmagic reports `application/CDFV2` for some genuine Word 97-2003
     * files on this build. `application/octet-stream` (libmagic's
     * "unrecognised") is NEVER accepted for anything — it would let arbitrary
     * binaries through as .doc/.docx. What actually proves the file is real is
     * the structural check in looksLikeDocx()/looksLikeOle2() below.
     */
    private const ALLOWED_MIME = [
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword', 'application/CDFV2', 'application/vnd.ms-office'],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
        ],
    ];

    /** The OLE2 compound-file signature every genuine legacy .doc file begins with. */
    private const OLE2_SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    public function dashboard(): void
    {
        Guard::requireRole('Faculty');

        $user = Auth::user();
        $facultyId = (int) ($user['user_id'] ?? 0);
        $period = AcademicPeriod::active();

        if ($period !== null) {
            $this->generateReminders($facultyId, (int) $period['period_id']);
        }

        $counts = $period !== null
            ? Submission::statusCountsForFaculty($facultyId, (int) $period['period_id'])
            : ['Pending' => 0, 'Submitted' => 0, 'Approved' => 0, 'Revised' => 0];

        // FR-39: the deadline calendar. The month comes from the existing
        // dashboard route's `month` query parameter (no new route), validated
        // to YYYY-MM by the model before it reaches either the date arithmetic
        // or the prev/next links.
        //
        // Scoped in SQL to this faculty member's own submission rows, bound to
        // the session's user id — the same id the status counts above are
        // read with. A requirement published to another program or to other
        // named individuals (FR-35) has no row for them and so cannot appear,
        // and nothing is narrowed afterwards in PHP.
        $today = date('Y-m-d');
        $calendarWindow = DeadlineCalendar::window(DeadlineCalendar::month($_GET['month'] ?? null));
        $calendarDays = $period !== null
            ? DeadlineCalendar::byDay(DeadlineCalendar::forFaculty(
                $facultyId,
                (int) $period['period_id'],
                $calendarWindow['start'],
                $calendarWindow['end'],
                $today
            ))
            : [];

        $this->view('dashboard/faculty', [
            'appName'        => $this->config()['app']['name'],
            'user'           => $user,
            'period'         => $period,
            'counts'         => $counts,
            'calendarWindow' => $calendarWindow,
            'calendarDays'   => $calendarDays,
            'calendarToday'  => $today,
        ]);
    }

    public function requirements(): void
    {
        Guard::requireRole('Faculty');

        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        $facultyId = (int) (Auth::user()['user_id'] ?? 0);
        $period = AcademicPeriod::active();

        if ($period !== null) {
            // Self-heal a new hire's checklist: a faculty account created
            // after a requirement was published has no submission row for it
            // (publishing snapshots activeFacultyIds() at that moment). Never
            // let a backfill failure break the page.
            try {
                Submission::backfillForFaculty($facultyId, (int) $period['period_id']);
            } catch (Throwable $e) {
                error_log('[CCIS-DMS] requirement backfill failed for faculty ' . $facultyId . ': ' . $e->getMessage());
            }

            // Runs AFTER the backfill so a newly-created Pending row can raise
            // its own deadline reminder on the very same page load.
            $this->generateReminders($facultyId, (int) $period['period_id']);
        }

        $checklist = $period !== null
            ? Submission::checklistForFaculty($facultyId, (int) $period['period_id'])
            : [];

        $this->view('faculty/requirements', [
            'appName'   => $this->config()['app']['name'],
            'period'    => $period,
            'checklist' => $checklist,
            'flash'     => $flash,
            'csrf'      => Csrf::token(),
        ]);
    }

    public function upload(string $id): void
    {
        Guard::requireRole('Faculty');

        // A POST body larger than post_max_size makes PHP silently drop the
        // entire $_POST/$_FILES payload (no CSRF token, no file) while still
        // reporting the original Content-Length — catch that here with its
        // own message, before the CSRF check would otherwise fire a generic
        // "session expired" error for what is really a size problem.
        if ($this->postExceededSizeLimit()) {
            $this->flash('err', 'File exceeds the 10 MB limit.');
            $this->redirectToRequirements();
            return;
        }

        $token = (string) ($_POST['csrf_token'] ?? '');
        if (!Csrf::verify($token)) {
            $this->flash('err', 'Your session has expired. Please try again.');
            $this->redirectToRequirements();
            return;
        }

        $facultyId = (int) (Auth::user()['user_id'] ?? 0);
        $submissionId = (int) $id;

        $submission = Submission::findOwned($submissionId, $facultyId);
        if ($submission === null) {
            $this->notFoundPage();
            return;
        }

        // A closed period is read-only, and ownership plus status does not say
        // so: an item left Pending or Revised when the period closed is still
        // owned and still uploadable by those two tests alone. The checklist
        // stops showing it, but this POST route is reachable from the URL the
        // app itself rendered while the item was outstanding, so the refusal
        // has to live here. Checked BEFORE status, because "the period closed"
        // is the more fundamental reason and the more useful message.
        if ((int) $submission['period_is_active'] !== 1) {
            $this->flash('err', 'That academic period has been closed. Documents can no longer be uploaded for it.');
            $this->redirectToRequirements();
            return;
        }

        if (!in_array($submission['status'], ['Pending', 'Revised'], true)) {
            $this->flash('err', "This requirement can't be uploaded to right now.");
            $this->redirectToRequirements();
            return;
        }

        [$upload, $error] = $this->validateUpload($_FILES['document'] ?? null);
        if ($error !== null) {
            $this->flash('err', $error);
            $this->redirectToRequirements();
            return;
        }

        $newVersion = (int) $submission['current_version'] + 1;
        $storedName = sprintf('sub%d_v%d_%s.%s', $submissionId, $newVersion, bin2hex(random_bytes(8)), $upload['ext']);

        $storageDir = dirname(__DIR__, 2) . '/storage/uploads';
        if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
            $this->flash('err', 'Could not store the uploaded file. Please try again.');
            $this->redirectToRequirements();
            return;
        }

        $destination = $storageDir . '/' . $storedName;
        if (!move_uploaded_file($upload['tmpName'], $destination)) {
            $this->flash('err', 'Could not store the uploaded file. Please try again.');
            $this->redirectToRequirements();
            return;
        }

        $relativePath = 'storage/uploads/' . $storedName;
        $wasRevised = $submission['status'] === 'Revised';
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        $pdo = Database::connection($this->config()['db']);
        $pdo->beginTransaction();

        try {
            // markSubmitted() is called FIRST and guards on status: if a
            // concurrent upload already flipped this submission's status
            // between our findOwned() check above and here (TOCTOU), it
            // returns 0 rows affected and we must roll back WITHOUT ever
            // inserting a document_files row — otherwise two uploads can
            // both compute the same next version and both insert.
            $affected = Submission::markSubmitted($submissionId, $newVersion);

            if ($affected === 0) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                @unlink($destination);
                $this->flash('err', "This requirement's status changed — please reload and try again.");
                $this->redirectToRequirements();
                return;
            }

            DocumentFile::create(
                $submissionId,
                $facultyId,
                $upload['originalName'],
                $relativePath,
                $upload['mimeType'],
                $upload['size'],
                $newVersion
            );

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            @unlink($destination);
            throw $e;
        }

        // Best-effort, post-commit: tell the Secretary something is waiting
        // (FR-21). The queue is shared, so every active Secretary is notified
        // rather than one assignee. Must never undo a stored upload.
        try {
            $reviewerIds = User::activeSecretaryIds();
            if ($reviewerIds !== []) {
                Notification::createForMany(
                    $reviewerIds,
                    $submissionId,
                    'status_change',
                    $wasRevised ? 'Document resubmitted for review' : 'New document awaiting review',
                    mb_substr('"' . $submission['title'] . '" was ' . ($wasRevised ? 'resubmitted' : 'submitted')
                        . ' and is waiting in the review queue.', 0, 255)
                );
            }
        } catch (Throwable $e) {
            error_log('[CCIS-DMS] reviewer notification failed for submission ' . $submissionId . ': ' . $e->getMessage());
        }

        // Best-effort, post-commit: an audit-log write must never 500 an
        // upload that already succeeded (e.g. a long original filename could
        // otherwise exceed audit_log.details under strict SQL mode).
        try {
            AuditLog::record(
                $facultyId,
                $wasRevised ? 'resubmit' : 'submit',
                'submission',
                $submissionId,
                'v' . $newVersion . ' ' . $upload['originalName'],
                $ip
            );
        } catch (Throwable $e) {
            error_log('[CCIS-DMS] audit log write failed for submission ' . $submissionId . ': ' . $e->getMessage());
        }

        $this->flash('ok', 'Uploaded — status is now Submitted.');
        $this->redirectToRequirements();
    }

    /**
     * Raise any due deadline / overdue reminders for this faculty member
     * (FR-21). Called on the faculty landing screens because the system has no
     * scheduler; the generators are idempotent per submission per day, so
     * calling them on every page load produces at most one reminder each.
     *
     * Best-effort by design: a reminder is a convenience, and a failure here
     * must never stop a faculty member from seeing their checklist.
     */
    private function generateReminders(int $facultyId, int $periodId): void
    {
        $today = date('Y-m-d');

        try {
            Notification::generateDeadlineReminders($facultyId, $periodId, $today);
            Notification::generateOverdueReminders($facultyId, $periodId, $today);
        } catch (Throwable $e) {
            error_log('[CCIS-DMS] reminder generation failed for faculty ' . $facultyId . ': ' . $e->getMessage());
        }
    }

    /**
     * True when the request POSTed a body that exceeded post_max_size: PHP
     * empties BOTH $_POST and $_FILES in that case but still sets
     * CONTENT_LENGTH from the request headers.
     */
    private function postExceededSizeLimit(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return false;
        }

        if ($_POST !== [] || $_FILES !== []) {
            return false;
        }

        return (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;
    }

    /**
     * Validate the uploaded "document" file: presence, size, extension, and
     * detected MIME type. Never stores a rejected file.
     *
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int}|null $file
     * @return array{0:array{tmpName:string,ext:string,mimeType:string,size:int,originalName:string},1:?string}
     */
    private function validateUpload(?array $file): array
    {
        $empty = ['tmpName' => '', 'ext' => '', 'mimeType' => '', 'size' => 0, 'originalName' => ''];

        if ($file === null || !isset($file['error'])) {
            return [$empty, 'Choose a PDF or Word document to upload.'];
        }

        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            return [$empty, 'File exceeds the 10 MB limit.'];
        }

        if ($file['error'] === UPLOAD_ERR_NO_FILE) {
            return [$empty, 'Choose a PDF or Word document to upload.'];
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            return [$empty, 'The upload failed. Please try again.'];
        }

        $size = (int) ($file['size'] ?? 0);

        // Separate messages: a 0-byte file used to be told it was over the 10 MB
        // limit, which sends someone off to shrink a document that is already
        // empty. The avatar validator has always said this correctly; the two
        // now agree.
        if ($size <= 0) {
            return [$empty, 'That file is empty. Choose a document with content in it.'];
        }

        if ($size > self::MAX_UPLOAD_BYTES) {
            return [$empty, 'File exceeds the 10 MB limit.'];
        }

        $originalName = basename((string) ($file['name'] ?? ''));
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED_MIME[$ext])) {
            return [$empty, 'Only PDF or Word documents (.pdf, .doc, .docx) are allowed.'];
        }

        // audit_log.details is VARCHAR(255) and the upload audit entry is
        // 'v'.$version.' '.$originalName with no other cap — under strict SQL
        // mode (the production default) an over-long value would throw AFTER
        // the upload already committed. Cap the name here, preserving the
        // extension, so that can never happen.
        $originalName = $this->truncateFileName($originalName, $ext);

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            return [$empty, 'The upload failed. Please try again.'];
        }

        $detectedMime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmpName);
        if (!in_array($detectedMime, self::ALLOWED_MIME[$ext], true)) {
            return [$empty, 'The uploaded file does not look like a valid PDF or Word document.'];
        }

        // finfo passing is necessary but not sufficient for .doc/.docx — a
        // structural check is what's actually authoritative (see the
        // ALLOWED_MIME doc comment).
        if ($ext === 'docx' && !$this->looksLikeDocx($tmpName)) {
            return [$empty, 'The uploaded file does not look like a valid PDF or Word document.'];
        }

        if ($ext === 'doc' && !$this->looksLikeOle2($tmpName)) {
            return [$empty, 'The uploaded file does not look like a valid PDF or Word document.'];
        }

        return [[
            'tmpName'      => $tmpName,
            'ext'          => $ext,
            'mimeType'     => $detectedMime,
            'size'         => $size,
            'originalName' => $originalName,
        ], null];
    }

    /**
     * Cap an original filename at 200 characters, preserving the extension —
     * the base name is truncated and the `.ext` re-appended, rather than a
     * flat substr that could clip the extension off entirely.
     */
    private function truncateFileName(string $originalName, string $ext): string
    {
        if (mb_strlen($originalName) <= 200) {
            return $originalName;
        }

        $suffix = $ext !== '' ? '.' . $ext : '';
        $base = $ext !== '' ? mb_substr($originalName, 0, -(mb_strlen($ext) + 1)) : $originalName;

        return mb_substr($base, 0, 200 - mb_strlen($suffix)) . $suffix;
    }

    /**
     * True when the tmp file is a real OOXML zip container: both
     * `[Content_Types].xml` and `word/document.xml` must be present. finfo
     * alone can't distinguish a genuine .docx from an arbitrary zip (or
     * binary that merely sniffs as `application/zip`) renamed to .docx —
     * this structural check is what's actually authoritative.
     */
    private function looksLikeDocx(string $tmpName): bool
    {
        $zip = new ZipArchive();
        if ($zip->open($tmpName) !== true) {
            return false;
        }

        $hasContentTypes = $zip->locateName('[Content_Types].xml') !== false;
        $hasDocument = $zip->locateName('word/document.xml') !== false;

        $zip->close();

        return $hasContentTypes && $hasDocument;
    }

    /**
     * True when the tmp file begins with the OLE2 compound-file signature
     * every genuine legacy .doc (Word 97-2003) file has. finfo alone isn't
     * authoritative here: it reports `application/octet-stream` for
     * arbitrary binaries renamed to .doc, and `application/CDFV2` for some
     * genuine Word files on this build — the magic bytes are what actually
     * prove it.
     */
    private function looksLikeOle2(string $tmpName): bool
    {
        $handle = fopen($tmpName, 'rb');
        if ($handle === false) {
            return false;
        }

        $header = fread($handle, 8);
        fclose($handle);

        return $header === self::OLE2_SIGNATURE;
    }

    private function notFoundPage(): void
    {
        http_response_code(404);
        $this->view('errors/404', ['appName' => $this->config()['app']['name']]);
    }

    private function flash(string $type, string $message): void
    {
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    }

    private function redirectToRequirements(): void
    {
        header('Location: ' . url('/faculty/requirements'));
        exit;
    }
}
