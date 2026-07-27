<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Guard;
use App\Models\AcademicPeriod;
use App\Models\Submission;

/**
 * Read-only archive of past academic periods (FR-32, FR-33, FR-34).
 *
 * Archiving here is by ASSOCIATION, not by moving data: a submission is
 * "archived" the moment its academic period stops being the active one. That
 * is why nothing is ever deleted (FR-32) and why the active-period screens
 * stay clean (FR-34) — the history is still in the same tables, just reached
 * through a different door.
 *
 * Any authenticated user may browse, but WHAT they see depends on their role:
 * The Secretary sees every faculty member's history, while a Faculty user sees
 * only their own. That restriction is applied as a
 * SQL predicate in Submission::archiveForPeriod(), not by filtering rows after
 * they have been fetched, so there is no way to widen it from the request.
 *
 * Every screen here is read-only by construction: no state-changing action is
 * routed to this controller, so a past period cannot be edited back into life.
 * Files remain downloadable through the existing authenticated download route,
 * which applies its own ownership check.
 */
final class ArchiveController extends Controller
{
    public function index(): void
    {
        Guard::requireAuth();

        $this->view('archive/index', [
            'appName' => $this->config()['app']['name'],
            'periods' => AcademicPeriod::archivable(),
            'active'  => AcademicPeriod::active(),
        ]);
    }

    public function show(string $id): void
    {
        Guard::requireAuth();

        $periodId = (int) $id;
        $period = AcademicPeriod::find($periodId);
        if ($period === null) {
            $this->notFoundPage();
            return;
        }

        // A Faculty user's archive is their own history and nothing else.
        $facultyId = Auth::hasRole('Faculty')
            ? (int) (Auth::user()['user_id'] ?? 0)
            : null;

        $this->view('archive/show', [
            'appName'     => $this->config()['app']['name'],
            'period'      => $period,
            'submissions' => Submission::archiveForPeriod($periodId, $facultyId),
            'ownOnly'     => $facultyId !== null,
        ]);
    }

    private function notFoundPage(): void
    {
        http_response_code(404);
        $this->view('errors/404', ['appName' => $this->config()['app']['name']]);
    }
}
