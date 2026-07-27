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

    private function notFoundPage(): void
    {
        http_response_code(404);
        $this->view('errors/404', ['appName' => $this->config()['app']['name']]);
    }
}
