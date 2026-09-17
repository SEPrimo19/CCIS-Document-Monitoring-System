<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\DocumentConverter;
use App\Core\DocxHtml;
use App\Core\DocxText;
use App\Core\Guard;
use App\Models\DocumentFile;
use ZipArchive;

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
     * The in-app document viewer (FR-41).
     *
     * The client asked to read a submission inside the system rather than
     * download it, and every list that reaches a document now opens here. What
     * the page can show depends on the format, and it says so rather than
     * pretending:
     *
     *  - PDF   rendered in place, in a frame fed by preview() below.
     *  - DOCX  its text, extracted (DocxText). Not a rendering — tables
     *          flatten and images are gone — but readable, which is what a
     *          reviewer needs to reach a decision. Most of this college's
     *          paperwork is Word, so without this the viewer would have said
     *          "download to read" for nearly every document.
     *  - DOC   download only. The legacy binary format is not a ZIP of XML and
     *          cannot be read without a converter this deployment does not have.
     *
     * Authorization is download()'s, unchanged, including 404-not-403 for a
     * document that exists but is not yours: a third route to the same bytes
     * must not become a softer one.
     */
    public function show(string $fileId): void
    {
        Guard::requireAuth();

        $file = DocumentFile::findForViewer((int) $fileId);

        if ($file === null) {
            $this->notFoundPage();
            return;
        }

        $user = Auth::user();
        $role = $user['role_name'] ?? '';
        $isOwningFaculty = $role === 'Faculty' && (int) $file['faculty_id'] === (int) ($user['user_id'] ?? 0);

        if ($role !== 'Secretary' && !$isOwningFaculty) {
            $this->notFoundPage();
            return;
        }

        $absolutePath = dirname(__DIR__, 2) . '/storage/uploads/' . basename($file['file_path']);
        $ext = strtolower(pathinfo((string) $file['file_name'], PATHINFO_EXTENSION));

        $missing = !is_file($absolutePath);
        $mode = 'download-only';
        $text = null;
        $html = null;
        $textStatus = null;
        $truncated = false;

        if (!$missing) {
            if ($ext === 'pdf' && $file['mime_type'] === 'application/pdf') {
                $mode = 'pdf';
            } elseif (in_array($ext, DocumentConverter::CONVERTIBLE, true)
                      && DocumentConverter::available()
                      && DocumentConverter::pdfFor($absolutePath, (int) $file['file_id']) !== null) {
                // Converted to PDF and shown in the browser's own PDF viewer:
                // pages, zoom and the real layout, which is what "view the
                // document" actually means.
                //
                // The conversion is ATTEMPTED here rather than assumed from the
                // binary being present, because the only thing worse than no
                // preview is an empty frame: if the conversion fails at serve
                // time the viewer has already committed to showing one, and the
                // reader gets a blank box with no explanation. Doing it now
                // costs the first open a few seconds and is cached after, and a
                // failure falls through to the rendered contents below.
                $mode = 'pdf';
            } elseif ($ext === 'docx') {
                // Rendered as a document first — paragraphs, emphasis, tables
                // and images — because "view it in the app" means seeing the
                // document, not a transcript of it. The plain-text extract is
                // kept as the fallback for a file the renderer cannot make
                // sense of, so an odd .docx still shows something readable
                // rather than nothing.
                $rendered = DocxHtml::render($absolutePath, (int) $file['file_id']);
                $textStatus = $rendered['status'];
                $truncated = $rendered['truncated'];

                if ($rendered['status'] === DocxHtml::OK) {
                    $mode = 'html';
                    $html = $rendered['html'];
                } else {
                    $extracted = DocxText::extract($absolutePath);

                    if ($extracted['status'] === DocxText::OK) {
                        $mode = 'text';
                        $text = $extracted['text'];
                        $truncated = $extracted['truncated'];
                        $textStatus = DocxText::OK;
                    } else {
                        $textStatus = $extracted['status'];
                    }
                }
            }
        }

        $this->view('documents/show', [
            'appName'     => $this->config()['app']['name'],
            // NOT 'file': Controller::view() does extract($data, EXTR_SKIP)
            // and already holds a local $file (the view's own path), so that
            // key is silently dropped and the view receives a string.
            'document'    => $file,
            'ext'         => $ext,
            'mode'        => $mode,
            'text'        => $text,
            'html'        => $html,
            'textStatus'  => $textStatus,
            'truncated'   => $truncated,
            'missing'     => $missing,
            'isSecretary' => $role === 'Secretary',
        ]);
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
        $absolutePath = dirname(__DIR__, 2) . '/storage/uploads/' . basename($file['file_path']);

        if (!is_file($absolutePath)) {
            $this->notFoundPage();
            return;
        }

        if ($ext === 'pdf' && $file['mime_type'] === 'application/pdf') {
            $servePath = $absolutePath;
            $displayName = (string) $file['file_name'];
        } elseif (in_array($ext, DocumentConverter::CONVERTIBLE, true)) {
            // A Word document is served as its converted PDF. The conversion is
            // cached on the first request; a later one is a file read.
            $servePath = DocumentConverter::pdfFor($absolutePath, (int) $file['file_id']);

            if ($servePath === null) {
                // No converter installed, or the conversion failed. The viewer
                // has already fallen back to the rendered HTML, so there is
                // nothing to frame here.
                $this->notFoundPage();
                return;
            }

            $displayName = pathinfo((string) $file['file_name'], PATHINFO_FILENAME) . '.pdf';
        } else {
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
        $displayName = str_replace(["\r", "\n", '"'], '', $displayName);

        header('Content-Type: application/pdf');
        header('Content-Length: ' . (string) filesize($servePath));
        header('Content-Disposition: inline; filename="' . $displayName . '"');

        readfile($servePath);
        exit;
    }

    /**
     * One image embedded in a .docx, for the rendered view (FR-41).
     *
     * The relationship id is NOT trusted as a path. It is looked up in the
     * relationship map the document itself declares, and only entries under
     * word/media/ are in that map, so a crafted id cannot address another entry
     * in the archive or anything on disk. The bytes are then typed by INSPECTING
     * them, never by their name, and anything that is not a real raster image is
     * refused — so a file renamed to .png inside the package cannot be served
     * back as one.
     *
     * Same ownership test as the rest of this controller, including 404 rather
     * than 403, because this is one more route to the same document.
     */
    public function media(string $fileId, string $relId): void
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

        if ($role !== 'Secretary' && !$isOwningFaculty) {
            $this->notFoundPage();
            return;
        }

        $absolutePath = dirname(__DIR__, 2) . '/storage/uploads/' . basename($file['file_path']);
        $ext = strtolower(pathinfo((string) $file['file_name'], PATHINFO_EXTENSION));

        if ($ext !== 'docx' || !is_file($absolutePath)) {
            $this->notFoundPage();
            return;
        }

        $map = DocxHtml::imageMap($absolutePath);
        $entry = $map[$relId] ?? null;

        if ($entry === null) {
            $this->notFoundPage();
            return;
        }

        $zip = new ZipArchive();

        if ($zip->open($absolutePath) !== true) {
            $this->notFoundPage();
            return;
        }

        try {
            $stat = $zip->statName($entry);

            // 8 MB is far more than a document illustration needs and well under
            // anything that would trouble memory.
            if ($stat === false || (int) ($stat['size'] ?? 0) > 8 * 1024 * 1024) {
                $this->notFoundPage();
                return;
            }

            $bytes = $zip->getFromName($entry);
        } finally {
            $zip->close();
        }

        if (!is_string($bytes) || $bytes === '') {
            $this->notFoundPage();
            return;
        }

        $info = @getimagesizefromstring($bytes);
        $type = is_array($info) ? ($info[2] ?? null) : null;

        $servable = [
            IMAGETYPE_JPEG => 'image/jpeg',
            IMAGETYPE_PNG  => 'image/png',
            IMAGETYPE_GIF  => 'image/gif',
            IMAGETYPE_WEBP => 'image/webp',
            IMAGETYPE_BMP  => 'image/bmp',
        ];

        if (!is_int($type) || !isset($servable[$type])) {
            $this->notFoundPage();
            return;
        }

        header('Content-Type: ' . $servable[$type]);
        header('Content-Length: ' . (string) strlen($bytes));
        header('Content-Disposition: inline');
        header('Cache-Control: private, max-age=300');

        echo $bytes;
        exit;
    }

    private function notFoundPage(): void
    {
        http_response_code(404);
        $this->view('errors/404', ['appName' => $this->config()['app']['name']]);
    }
}
