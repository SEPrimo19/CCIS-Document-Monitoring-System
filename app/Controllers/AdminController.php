<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Guard;
use App\Models\AcademicPeriod;
use App\Models\DocumentType;
use App\Models\Requirement;
use App\Models\Submission;
use App\Models\User;

/**
 * Administrator landing + monitoring board (FR-17..FR-20). All figures and
 * the monitoring board are scoped to the single active academic period —
 * cross-period/archive views are a later slice. Other feature areas (user
 * accounts, reports, audit log) are built out later in Phase 4.
 */
final class AdminController extends Controller
{
    public function dashboard(): void
    {
        Guard::requireRole('Administrator');

        $period = AcademicPeriod::active();
        $figures = $period !== null ? $this->figuresForPeriod((int) $period['period_id']) : null;

        $this->view('dashboard/admin', [
            'appName' => $this->config()['app']['name'],
            'user'    => Auth::user(),
            'period'  => $period,
            'figures' => $figures,
        ]);
    }

    /**
     * Monitoring board: period-level figures (FR-18), the faculty x
     * requirement compliance matrix (FR-17) with overdue emphasis (FR-20),
     * and the filterable submission search (FR-19).
     */
    public function monitoring(): void
    {
        Guard::requireRole('Administrator');

        $period = AcademicPeriod::active();
        $docTypes = $this->activeDocumentTypes();

        if ($period === null) {
            $this->view('admin/monitoring/index', [
                'appName'      => $this->config()['app']['name'],
                'period'       => null,
                'figures'      => null,
                'requirements' => [],
                'faculty'      => [],
                'grid'         => [],
                'docTypes'     => $docTypes,
                'statuses'     => Submission::statuses(),
                'filters'      => ['faculty_name' => '', 'status' => '', 'doc_type_id' => null],
                'results'      => [],
            ]);
            return;
        }

        $periodId = (int) $period['period_id'];

        $facultyNameFilter = $this->facultyNameFilterFrom($_GET);
        $statusFilter = $this->statusFilterFrom($_GET);
        $docTypeFilter = $this->docTypeFilterFrom($_GET, $docTypes);

        $this->view('admin/monitoring/index', [
            'appName'      => $this->config()['app']['name'],
            'period'       => $period,
            'figures'      => $this->figuresForPeriod($periodId),
            'requirements' => Requirement::allForPeriod($periodId),
            'faculty'      => User::activeFaculty(),
            'grid'         => $this->gridLookup($periodId),
            'docTypes'     => $docTypes,
            'statuses'     => Submission::statuses(),
            'filters'      => [
                'faculty_name' => $facultyNameFilter ?? '',
                'status'       => $statusFilter ?? '',
                'doc_type_id'  => $docTypeFilter,
            ],
            'results'      => Submission::searchForPeriod($periodId, $facultyNameFilter, $statusFilter, $docTypeFilter),
        ]);
    }

    /**
     * Period-level figures shared by the dashboard summary and the
     * monitoring board's stat cards (FR-18): status counts, total
     * submissions, compliance rate, and overdue count.
     *
     * @return array{statusCounts:array{Pending:int,Submitted:int,Approved:int,'Returned-for-revision':int},total:int,complianceRate:int,overdueCount:int}
     */
    private function figuresForPeriod(int $periodId): array
    {
        $statusCounts = Submission::statusCountsForPeriod($periodId);
        $total = array_sum($statusCounts);
        $complianceRate = $total > 0 ? (int) round($statusCounts['Approved'] / $total * 100) : 0;

        return [
            'statusCounts'   => $statusCounts,
            'total'          => $total,
            'complianceRate' => $complianceRate,
            'overdueCount'   => Submission::overdueCountForPeriod($periodId),
        ];
    }

    /**
     * [faculty_id][requirement_id] => status lookup for the monitoring
     * matrix, built from the flat grid rows.
     *
     * @return array<int,array<int,string>>
     */
    private function gridLookup(int $periodId): array
    {
        $grid = [];
        foreach (Submission::gridForPeriod($periodId) as $row) {
            $grid[(int) $row['faculty_id']][(int) $row['requirement_id']] = $row['status'];
        }

        return $grid;
    }

    /**
     * Active document types only, for the search filter's dropdown.
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
     * The `faculty_name` GET filter, trimmed. Null when absent/blank.
     *
     * @param array<string,mixed> $query
     */
    private function facultyNameFilterFrom(array $query): ?string
    {
        $raw = trim((string) ($query['faculty_name'] ?? ''));

        return $raw === '' ? null : $raw;
    }

    /**
     * The `status` GET filter, validated against the submissions.status enum.
     * Null when absent or not a recognized status.
     *
     * @param array<string,mixed> $query
     */
    private function statusFilterFrom(array $query): ?string
    {
        $raw = trim((string) ($query['status'] ?? ''));

        return in_array($raw, Submission::statuses(), true) ? $raw : null;
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
}
