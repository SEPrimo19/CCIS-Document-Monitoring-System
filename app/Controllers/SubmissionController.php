<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Guard;
use App\Models\DocumentFile;
use App\Models\Review;
use App\Models\Submission;

/**
 * Read-only document-detail view (FR-11): one submission's metadata, its
 * status/decision history, the reviewer comments attached to each decision,
 * and every uploaded version.
 *
 * This is the screen that makes versioning visible. Resubmitting a returned
 * document already kept the earlier file (FR-10), but until now only the
 * current version was reachable from the checklist — the history existed in
 * the database with no way to see it.
 *
 * Access mirrors the download route so the two cannot disagree: a Faculty user
 * may only open their OWN submission, while the Secretary may open any. An
 * unauthorised id returns 404 rather than 403, so the page cannot be used to
 * probe which submission ids exist.
 */
final class SubmissionController extends Controller
{
    public function show(string $id): void
    {
        Guard::requireAuth();

        $submissionId = (int) $id;
        $submission = Submission::findForReview($submissionId);
        if ($submission === null) {
            $this->notFoundPage();
            return;
        }

        $user = Auth::user();
        $viewerId = (int) ($user['user_id'] ?? 0);

        if (Auth::hasRole('Faculty') && (int) $submission['faculty_id'] !== $viewerId) {
            $this->notFoundPage();
            return;
        }

        $this->view('submissions/show', [
            'appName'    => $this->config()['app']['name'],
            'submission' => $submission,
            'period'     => Submission::periodFor($submissionId),
            'versions'   => DocumentFile::versionsForSubmission($submissionId),
            'history'    => Review::historyForSubmission($submissionId),
            'isOwner'    => (int) $submission['faculty_id'] === $viewerId,
        ]);
    }

    private function notFoundPage(): void
    {
        http_response_code(404);
        $this->view('errors/404', ['appName' => $this->config()['app']['name']]);
    }
}
