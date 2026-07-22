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
use App\Models\DocumentType;
use App\Models\Notification;
use App\Models\Review;
use App\Models\Submission;
use Throwable;

/**
 * Reviewer/Approver review flow (FR-12..FR-15): a shared queue of Submitted
 * documents — no per-reviewer assignment, any Reviewer/Approver may act on
 * any item — and a single-step Approve / Return-for-revision decision, with
 * comments mandatory on return. Faculty compliance summary is built out
 * later in Phase 4.
 */
final class ReviewerController extends Controller
{
    public function dashboard(): void
    {
        Guard::requireRole('Reviewer/Approver');

        $period = AcademicPeriod::active();
        $awaitingCount = $period !== null ? Submission::awaitingReviewCount((int) $period['period_id']) : 0;

        $this->view('dashboard/reviewer', [
            'appName'       => $this->config()['app']['name'],
            'user'          => Auth::user(),
            'awaitingCount' => $awaitingCount,
        ]);
    }

    public function queue(): void
    {
        Guard::requireRole('Reviewer/Approver');

        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        $period = AcademicPeriod::active();
        $docTypes = $this->activeDocumentTypes();
        $selectedDocTypeId = $this->docTypeFilterFrom($_GET, $docTypes);

        $queue = $period !== null
            ? Submission::queueForReview((int) $period['period_id'], $selectedDocTypeId)
            : [];

        $this->view('reviewer/queue', [
            'appName'           => $this->config()['app']['name'],
            'period'            => $period,
            'docTypes'          => $docTypes,
            'selectedDocTypeId' => $selectedDocTypeId,
            'queue'             => $queue,
            'flash'             => $flash,
            'csrf'              => Csrf::token(),
        ]);
    }

    public function review(string $id): void
    {
        Guard::requireRole('Reviewer/Approver');

        $submission = Submission::findForReview((int) $id);
        if ($submission === null) {
            $this->notFoundPage();
            return;
        }

        $this->renderReview($submission, Review::historyForSubmission((int) $id), '', '', []);
    }

    public function decide(string $id): void
    {
        Guard::requireRole('Reviewer/Approver');

        $submissionId = (int) $id;

        $token = (string) ($_POST['csrf_token'] ?? '');
        if (!Csrf::verify($token)) {
            $this->flash('err', 'Your session has expired. Please try again.');
            $this->redirectToQueue();
            return;
        }

        $submission = Submission::findForReview($submissionId);
        if ($submission === null) {
            $this->notFoundPage();
            return;
        }

        if ($submission['status'] !== 'Submitted') {
            $this->flash('err', 'This submission has already been reviewed.');
            $this->redirectToQueue();
            return;
        }

        $decision = (string) ($_POST['decision'] ?? '');
        $comments = trim((string) ($_POST['comments'] ?? ''));
        $history = Review::historyForSubmission($submissionId);

        if (!in_array($decision, ['Approved', 'Returned-for-revision'], true)) {
            $this->renderReview($submission, $history, $decision, $comments, [
                'decision' => 'Select a decision.',
            ]);
            return;
        }

        if ($decision === 'Returned-for-revision' && $comments === '') {
            $this->renderReview($submission, $history, $decision, $comments, [
                'comments' => 'Comments are required when returning a document.',
            ]);
            return;
        }

        $reviewerId = (int) (Auth::user()['user_id'] ?? 0);
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        // reviews.comments is nullable — store NULL for an approval left blank,
        // never an empty string.
        $storedComments = $comments === '' ? null : $comments;

        // The hidden `current_version` field round-trips the version this
        // reviewer actually opened (see review.php) — an invalid/missing
        // value can never match a real current_version, so it safely falls
        // through to the same "changed since you opened it" guard below.
        $currentVersionRaw = (string) ($_POST['current_version'] ?? '');
        $expectedVersion = ctype_digit($currentVersionRaw) ? (int) $currentVersionRaw : -1;

        $pdo = Database::connection($this->config()['db']);
        $pdo->beginTransaction();

        try {
            $affected = Submission::markReviewed($submissionId, $decision, $expectedVersion);

            if ($affected === 0) {
                // Lost the race: either another reviewer already decided this
                // shared-queue item, or faculty uploaded a new version after
                // this reviewer opened it (so they'd be deciding on a version
                // they never saw) — either way, between our page load and
                // this submit.
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $this->flash('err', 'This submission changed since you opened it. Please review it again.');
                $this->redirectToQueue();
                return;
            }

            Review::create($submissionId, $reviewerId, $decision, $storedComments);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // Best-effort, post-commit: a notification is a secondary side-effect
        // and must never undo an already-recorded decision (mirrors the
        // password-rehash best-effort pattern in Auth::attempt()).
        try {
            $title = $decision === 'Approved'
                ? 'Document approved'
                : 'Document returned for revision';
            $message = $decision === 'Approved'
                ? 'Your submission for "' . $submission['title'] . '" was approved.'
                : 'Your submission for "' . $submission['title'] . '" was returned for revision. Please review the comments and resubmit.';

            Notification::create((int) $submission['faculty_id'], $submissionId, 'status_change', $title, $message);
        } catch (Throwable $e) {
            error_log('[CCIS-DMS] notification create failed for submission ' . $submissionId . ': ' . $e->getMessage());
        }

        // Best-effort, post-commit: an audit-log write must never 500 a
        // decision that already succeeded.
        try {
            $detail = $submission['title'] . ($storedComments !== null ? '; ' . mb_substr($storedComments, 0, 120) : '');
            AuditLog::record(
                $reviewerId,
                $decision === 'Approved' ? 'approve' : 'return',
                'submission',
                $submissionId,
                $detail,
                $ip
            );
        } catch (Throwable $e) {
            error_log('[CCIS-DMS] audit log write failed for submission ' . $submissionId . ': ' . $e->getMessage());
        }

        $this->flash('ok', $decision === 'Approved' ? 'Document approved.' : 'Document returned for revision.');
        $this->redirectToQueue();
    }

    /**
     * Active document types only, for the queue's filter dropdown.
     *
     * @return list<array{doc_type_id:int,name:string,description:?string,is_active:int,created_by:?int,created_at:string}>
     */
    private function activeDocumentTypes(): array
    {
        return array_values(array_filter(
            DocumentType::all(),
            static fn(array $type): bool => (int) $type['is_active'] === 1
        ));
    }

    /**
     * The `doc_type_id` GET filter, validated against the active document
     * types list. Null when absent, non-numeric, or not a valid active type.
     *
     * @param array<string,mixed> $query
     * @param list<array{doc_type_id:int,name:string,description:?string,is_active:int,created_by:?int,created_at:string}> $activeDocTypes
     */
    private function docTypeFilterFrom(array $query, array $activeDocTypes): ?int
    {
        $raw = trim((string) ($query['doc_type_id'] ?? ''));
        if ($raw === '' || !ctype_digit($raw)) {
            return null;
        }

        $id = (int) $raw;
        $validIds = array_map(static fn(array $type): int => (int) $type['doc_type_id'], $activeDocTypes);

        return in_array($id, $validIds, true) ? $id : null;
    }

    /**
     * @param array{submission_id:int,status:string,current_version:int,submitted_at:?string,updated_at:string,title:string,description:?string,deadline:?string,doc_type_name:string,faculty_name:string,file_id:?int,file_name:?string,faculty_id:int} $submission
     * @param list<array{review_id:int,decision:string,comments:?string,reviewed_at:string,reviewer_name:string}> $history
     * @param array<string,string> $errors
     */
    private function renderReview(array $submission, array $history, string $decision, string $comments, array $errors): void
    {
        $this->view('reviewer/review', [
            'appName'    => $this->config()['app']['name'],
            'submission' => $submission,
            'history'    => $history,
            'decision'   => $decision,
            'comments'   => $comments,
            'errors'     => $errors,
            'csrf'       => Csrf::token(),
        ]);
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

    private function redirectToQueue(): void
    {
        header('Location: ' . url('/reviewer/queue'));
        exit;
    }
}
