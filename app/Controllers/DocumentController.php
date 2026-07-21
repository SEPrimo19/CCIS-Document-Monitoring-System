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
        $canAccess = in_array($role, ['Administrator', 'Reviewer/Approver'], true) || $isOwningFaculty;

        if (!$canAccess) {
            $this->forbiddenPage();
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

    private function forbiddenPage(): void
    {
        http_response_code(403);
        $this->view('errors/403', ['appName' => $this->config()['app']['name']]);
    }
}
