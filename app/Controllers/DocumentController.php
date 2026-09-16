<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Guard;
use App\Models\DocumentFile;

/**
 * Access-controlled document download (supports FR-7/FR-8). Files are stored
 * outside the web root (storage/uploads/) and served ONLY through this
 * authenticated route — never as static public files.
 */
final class DocumentController extends Controller
{
    public function download(string $fileId): void
    {
        Guard::requireAuth();

        $file = DocumentFile::findWithOwner((int) $fileId);
        if ($file === null) {
            $this->notFoundPage();
            return;
        }

        $user = Auth::user();
        $role = $user['role_name'] ?? '';
        $isOwningFaculty = $role === 'Faculty' && (int) $file['faculty_id'] === (int) ($user['user_id'] ?? 0);
        $canAccess = $role === 'Secretary' || $isOwningFaculty;

        if (!$canAccess) {
            // 404, not 403, for a faculty member requesting a file that isn't
            // theirs: a 403 would confirm the file id EXISTS (an enumeration
            // oracle), while a nonexistent id already 404s above. Returning the
            // same 404 for both makes existing and forbidden ids indistinguish-
            // able — matching the anti-enumeration stance of the /submissions
            // detail route.
            $this->notFoundPage();
            return;
        }

        $absolutePath = dirname(__DIR__, 2) . '/storage/uploads/' . basename($file['file_path']);
        if (!is_file($absolutePath)) {
            $this->notFoundPage();
            return;
        }

        $mimeType = $file['mime_type'] !== '' ? $file['mime_type'] : 'application/octet-stream';
        $downloadName = str_replace(["\r", "\n", '"'], '', $file['file_name']);

        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . (string) filesize($absolutePath));
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');

        readfile($absolutePath);
        exit;
    }

    /**
     * Render a submitted document INSIDE the app (FR-41), so the Secretary can
     * read it on the review screen instead of downloading it, deciding, and
     * coming back.
     *
     * Only PDFs are served here, and that is a browser limitation rather than a
     * policy choice: .doc and .docx have no native renderer, this project has no
     * document-conversion library, and the alternative — handing the file to an
     * external preview service — would send staff compliance documents off
     * campus and is refused outright. Anything that is not a PDF answers 404
     * and the review screen offers a download instead, which is the honest
     * behaviour rather than an empty frame.
     *
     * Authorization is identical to download(), including answering 404 rather
     * than 403 for a file that exists but is not yours: a 403 would confirm the
     * id is real. Adding a second route to the same bytes must not add a second,
     * weaker way of reaching them.
     */
    public function preview(string $fileId): void
    {
        Guard::requireAuth();

        $file = DocumentFile::findWithOwner((int) $fileId);
        if ($file === null) {
            $this->notFoundPage();
            return;
        }

        $user = Auth::user();
        $role = $user['role_name'] ?? '';
        $isOwningFaculty = $role === 'Faculty' && (int) $file['faculty_id'] === (int) ($user['user_id'] ?? 0);
        $canAccess = $role === 'Secretary' || $isOwningFaculty;

        if (!$canAccess) {
            $this->notFoundPage();
            return;
        }

        // The extension is checked as well as the recorded MIME type: the type
        // was written at upload time, and this route relaxes a security header,
        // so it re-derives rather than trusts.
        $ext = strtolower(pathinfo((string) $file['file_name'], PATHINFO_EXTENSION));

        if ($ext !== 'pdf' || $file['mime_type'] !== 'application/pdf') {
            $this->notFoundPage();
            return;
        }

        $absolutePath = dirname(__DIR__, 2) . '/storage/uploads/' . basename($file['file_path']);
        if (!is_file($absolutePath)) {
            $this->notFoundPage();
            return;
        }

        // THE ONE ROUTE IN THIS APPLICATION THAT MAY BE FRAMED.
        //
        // index.php sends X-Frame-Options: DENY and frame-ancestors 'none' on
        // every response, which blocks framing even from our own origin — so an
        // in-app viewer is impossible without relaxing them here. These two
        // header() calls REPLACE the global ones for this response only; every
        // other route, including the review screen doing the framing, keeps DENY
        // and remains unframeable.
        //
        // What is given up is narrow: this response can be embedded by a page on
        // this origin. It carries no controls, no session-changing links and no
        // forms, so there is nothing for a clickjacker to aim at. The CSP is
        // tightened to 'none' at the same time, so the PDF itself may not pull
        // in any subresource.
        header('X-Frame-Options: SAMEORIGIN');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'self'; base-uri 'none'; form-action 'none'");

        // Inline, and the filename is still sanitised: a quote or newline in it
        // would otherwise split the header.
        $displayName = str_replace(["\r", "\n", '"'], '', $file['file_name']);

        header('Content-Type: application/pdf');
        header('Content-Length: ' . (string) filesize($absolutePath));
        header('Content-Disposition: inline; filename="' . $displayName . '"');

        readfile($absolutePath);
        exit;
    }

    private function notFoundPage(): void
    {
        http_response_code(404);
        $this->view('errors/404', ['appName' => $this->config()['app']['name']]);
    }
}
