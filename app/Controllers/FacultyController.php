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
use App\Models\DocumentFile;
use App\Models\Submission;
use finfo;
use Throwable;

/**
 * Faculty landing + "My Requirements" checklist and upload (FR-6, FR-7,
 * FR-8). My Submissions / document detail are built out later in Phase 4.
 */
final class FacultyController extends Controller
{
    private const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

    /**
     * Extension => allowed detected MIME types. .doc/.docx often sniff as the
     * generic octet-stream (and .docx sometimes as plain zip, since OOXML
     * files ARE zip archives), so those are allowed only for their matching
     * extension — never for .pdf.
     */
    private const ALLOWED_MIME = [
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword', 'application/octet-stream'],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
            'application/octet-stream',
        ],
    ];

    public function dashboard(): void
    {
        Guard::requireRole('Faculty');

        $user = Auth::user();
        $facultyId = (int) ($user['user_id'] ?? 0);
        $period = AcademicPeriod::active();
        $counts = $period !== null
            ? Submission::statusCountsForFaculty($facultyId, (int) $period['period_id'])
            : ['Pending' => 0, 'Submitted' => 0, 'Approved' => 0, 'Returned-for-revision' => 0];

        $this->view('dashboard/faculty', [
            'appName' => $this->config()['app']['name'],
            'user'    => $user,
            'counts'  => $counts,
        ]);
    }

    public function requirements(): void
    {
        Guard::requireRole('Faculty');

        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        $facultyId = (int) (Auth::user()['user_id'] ?? 0);
        $period = AcademicPeriod::active();
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

        if (!in_array($submission['status'], ['Pending', 'Returned-for-revision'], true)) {
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
        $wasReturned = $submission['status'] === 'Returned-for-revision';
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        $pdo = Database::connection($this->config()['db']);
        $pdo->beginTransaction();

        try {
            DocumentFile::create(
                $submissionId,
                $facultyId,
                $upload['originalName'],
                $relativePath,
                $upload['mimeType'],
                $upload['size'],
                $newVersion
            );
            Submission::markSubmitted($submissionId, $newVersion);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            @unlink($destination);
            throw $e;
        }

        AuditLog::record(
            $facultyId,
            $wasReturned ? 'resubmit' : 'submit',
            'submission',
            $submissionId,
            'v' . $newVersion . ' ' . $upload['originalName'],
            $ip
        );

        $this->flash('ok', 'Uploaded — status is now Submitted.');
        $this->redirectToRequirements();
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
        if ($size <= 0 || $size > self::MAX_UPLOAD_BYTES) {
            return [$empty, 'File exceeds the 10 MB limit.'];
        }

        $originalName = basename((string) ($file['name'] ?? ''));
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED_MIME[$ext])) {
            return [$empty, 'Only PDF or Word documents (.pdf, .doc, .docx) are allowed.'];
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            return [$empty, 'The upload failed. Please try again.'];
        }

        $detectedMime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmpName);
        if (!in_array($detectedMime, self::ALLOWED_MIME[$ext], true)) {
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
