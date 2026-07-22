<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Guard;
use App\Models\AcademicPeriod;
use App\Models\AuditLog;
use App\Models\DocumentType;
use App\Models\Requirement;
use App\Models\Submission;
use App\Models\User;
use DateTime;

/**
 * Administrator landing, monitoring board (FR-17..FR-20), the audit log view
 * (FR-30, FR-31), and the reports + CSV export view (FR-24, FR-25). The
 * monitoring board is scoped to the single active academic period; reports,
 * unlike monitoring, let the administrator pick any academic period. User
 * accounts are built out later in Phase 4.
 */
final class AdminController extends Controller
{
    /** Row cap for the audit log view (FR-31) — newest N entries. */
    private const AUDIT_LOG_LIMIT = 200;

    /**
     * CSV report kinds accepted by exportCsv(), each with its filename slug
     * (used ONLY with the numeric period id to build the download filename —
     * never the period label or other user-supplied text, to avoid
     * header-injection) and its header row.
     */
    private const CSV_REPORTS = [
        'faculty' => [
            'slug'   => 'faculty-compliance',
            'header' => ['Faculty', 'Total', 'Pending', 'Submitted', 'Approved', 'Returned', 'Compliance %'],
        ],
        'doctype' => [
            'slug'   => 'doctype-completion',
            'header' => ['Document Type', 'Total', 'Pending', 'Submitted', 'Approved', 'Returned', 'Completion %'],
        ],
        'status' => [
            'slug'   => 'status-summary',
            'header' => ['Status', 'Count'],
        ],
    ];

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
            'faculty'      => User::facultyForPeriod($periodId),
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
     * Admin-only, read-only audit trail view (FR-30, FR-31): every
     * audit_log row, newest first, filterable by actor, action, and date
     * range. The log itself is append-only — this action never writes to
     * audit_log, it only reads from it.
     */
    public function auditLog(): void
    {
        Guard::requireRole('Administrator');

        $actors = AuditLog::distinctActors();
        $actions = AuditLog::distinctActions();

        $userIdFilter = $this->userIdFilterFrom($_GET, $actors);
        $actionFilter = $this->actionFilterFrom($_GET, $actions);
        $dateFromFilter = $this->dateFilterFrom($_GET, 'date_from');
        $dateToFilter = $this->dateFilterFrom($_GET, 'date_to');

        $limit = self::AUDIT_LOG_LIMIT;

        $this->view('admin/audit/index', [
            'appName' => $this->config()['app']['name'],
            'entries' => AuditLog::search($userIdFilter, $actionFilter, $dateFromFilter, $dateToFilter, $limit),
            'actors'  => $actors,
            'actions' => $actions,
            'filters' => [
                'user_id'   => $userIdFilter,
                'action'    => $actionFilter ?? '',
                'date_from' => $dateFromFilter ?? '',
                'date_to'   => $dateToFilter ?? '',
            ],
            'limit'   => $limit,
        ]);
    }

    /**
     * Admin reports (FR-24, FR-25): a period selector (any period, not just
     * the active one), the overall status summary + compliance rate, the
     * per-faculty compliance breakdown, and the per-document-type completion
     * breakdown. Each table is also exportable as CSV via exportCsv(), and
     * the whole page is printable (browser Print / Save-as-PDF). Read-only.
     */
    public function reports(): void
    {
        Guard::requireRole('Administrator');

        $periods = AcademicPeriod::all();
        $periodId = $this->periodIdFilterFrom($_GET, $periods);

        if ($periodId === null) {
            $active = AcademicPeriod::active();
            $periodId = $active !== null ? (int) $active['period_id'] : null;
        }

        $period = $periodId !== null ? $this->findPeriodInList($periods, $periodId) : null;

        if ($period === null) {
            $this->view('admin/reports/index', [
                'appName'           => $this->config()['app']['name'],
                'periods'           => $periods,
                'period'            => null,
                'figures'           => null,
                'facultyCompliance' => [],
                'docTypeCompletion' => [],
            ]);
            return;
        }

        $periodId = (int) $period['period_id'];

        $this->view('admin/reports/index', [
            'appName'           => $this->config()['app']['name'],
            'periods'           => $periods,
            'period'            => $period,
            'figures'           => $this->figuresForPeriod($periodId),
            'facultyCompliance' => Submission::complianceByFaculty($periodId),
            'docTypeCompletion' => Submission::completionByDocumentType($periodId),
        ]);
    }

    /**
     * Admin-only CSV export for the Reports page (FR-25): faculty
     * compliance, document-type completion, or the overall status summary,
     * selected via the `report` GET param. `period_id` is validated the same
     * way as reports() — anything invalid (missing/non-numeric/unknown period,
     * or an unrecognized `report` value) renders the branded 404 rather than
     * guessing what was meant. GET, read-only — no CSRF needed (mirrors the
     * monitoring/audit search forms). Streams the CSV to php://output and
     * exits without rendering the app layout.
     */
    public function exportCsv(): void
    {
        Guard::requireRole('Administrator');

        $periods = AcademicPeriod::all();
        $periodId = $this->periodIdFilterFrom($_GET, $periods);
        $report = $this->reportKindFrom($_GET);

        if ($periodId === null || $report === null) {
            $this->notFoundPage();
            return;
        }

        $meta = self::CSV_REPORTS[$report];

        // Filename is built ONLY from the fixed report slug + numeric period
        // id — never the period label or any other user-supplied text — so
        // there is nothing here an attacker could use for a header-injection
        // payload via the Content-Disposition header.
        $filename = sprintf('ccis-dms-%s-period-%d.csv', $meta['slug'], $periodId);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'wb');
        fputcsv($out, $meta['header']);

        foreach ($this->csvRowsFor($report, $periodId) as $row) {
            fputcsv($out, $row);
        }

        fclose($out);
        exit;
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
            'overdueCount'   => Submission::overdueCountForPeriod($periodId, date('Y-m-d')),
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

    /**
     * The `period_id` GET filter shared by reports() and exportCsv(),
     * validated against the FULL period list (every period, not just the
     * active one — an administrator may pull a report for a past period).
     * Null when absent, non-numeric, or not a real period id.
     *
     * @param array<string,mixed> $query
     * @param list<array{period_id:int,school_year:string,semester:string,label:?string,is_active:int}> $periods
     */
    private function periodIdFilterFrom(array $query, array $periods): ?int
    {
        $raw = trim((string) ($query['period_id'] ?? ''));
        if ($raw === '' || !ctype_digit($raw)) {
            return null;
        }

        $id = (int) $raw;

        return $this->findPeriodInList($periods, $id) !== null ? $id : null;
    }

    /**
     * @param list<array{period_id:int,school_year:string,semester:string,label:?string,is_active:int}> $periods
     * @return array{period_id:int,school_year:string,semester:string,label:?string,is_active:int}|null
     */
    private function findPeriodInList(array $periods, int $id): ?array
    {
        foreach ($periods as $candidate) {
            if ((int) $candidate['period_id'] === $id) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The `report` GET param for exportCsv(), validated against the known CSV
     * report kinds (self::CSV_REPORTS). Null when absent or not exactly one
     * of 'faculty' | 'doctype' | 'status' — the caller renders a 404 rather
     * than guessing which report was meant.
     *
     * @param array<string,mixed> $query
     */
    private function reportKindFrom(array $query): ?string
    {
        $raw = trim((string) ($query['report'] ?? ''));

        return array_key_exists($raw, self::CSV_REPORTS) ? $raw : null;
    }

    /**
     * The CSV data rows (header row is added separately by exportCsv()) for
     * one of the three report kinds, percentages computed the same way as
     * the view (Approved / total, 0 when total = 0).
     *
     * @return list<list<int|string>>
     */
    private function csvRowsFor(string $report, int $periodId): array
    {
        if ($report === 'faculty') {
            return array_map(
                static function (array $row): array {
                    $total = (int) $row['total'];

                    return [
                        $row['faculty_name'],
                        $total,
                        (int) $row['pending'],
                        (int) $row['submitted'],
                        (int) $row['approved'],
                        (int) $row['returned'],
                        self::percentOf((int) $row['approved'], $total),
                    ];
                },
                Submission::complianceByFaculty($periodId)
            );
        }

        if ($report === 'doctype') {
            return array_map(
                static function (array $row): array {
                    $total = (int) $row['total'];

                    return [
                        $row['doc_type_name'],
                        $total,
                        (int) $row['pending'],
                        (int) $row['submitted'],
                        (int) $row['approved'],
                        (int) $row['returned'],
                        self::percentOf((int) $row['approved'], $total),
                    ];
                },
                Submission::completionByDocumentType($periodId)
            );
        }

        // 'status'
        $rows = [];
        foreach ($this->figuresForPeriod($periodId)['statusCounts'] as $status => $count) {
            $rows[] = [$status, $count];
        }

        return $rows;
    }

    /**
     * A percentage, guarded against division by zero — the same shape as
     * figuresForPeriod()'s complianceRate, reused here for the per-row
     * compliance/completion percentages (FR-24, FR-25).
     */
    private static function percentOf(int $numerator, int $denominator): int
    {
        return $denominator > 0 ? (int) round($numerator / $denominator * 100) : 0;
    }

    /**
     * The `user_id` GET filter for the audit log (FR-31), validated against
     * the users who actually appear in the log. Null when absent, non-numeric,
     * or not one of those actor ids.
     *
     * @param array<string,mixed> $query
     * @param list<array{user_id:int,actor_name:string}> $actors
     */
    private function userIdFilterFrom(array $query, array $actors): ?int
    {
        $raw = trim((string) ($query['user_id'] ?? ''));
        if ($raw === '' || !ctype_digit($raw)) {
            return null;
        }

        $id = (int) $raw;
        $validIds = array_map(static fn(array $actor): int => (int) $actor['user_id'], $actors);

        return in_array($id, $validIds, true) ? $id : null;
    }

    /**
     * The `action` GET filter for the audit log (FR-31), validated against
     * the distinct actions actually present in the log. Null when absent or
     * not a recognized action.
     *
     * @param array<string,mixed> $query
     * @param list<string> $actions
     */
    private function actionFilterFrom(array $query, array $actions): ?string
    {
        $raw = trim((string) ($query['action'] ?? ''));

        return in_array($raw, $actions, true) ? $raw : null;
    }

    /**
     * A `date_from`/`date_to` GET filter for the audit log (FR-31), strictly
     * validated as YYYY-MM-DD (round-trip check via DateTime::createFromFormat,
     * same approach as RequirementController's deadline validation). Null when
     * absent, blank, or not a well-formed calendar date.
     *
     * @param array<string,mixed> $query
     */
    private function dateFilterFrom(array $query, string $key): ?string
    {
        $raw = trim((string) ($query[$key] ?? ''));
        if ($raw === '') {
            return null;
        }

        $date = DateTime::createFromFormat('Y-m-d', $raw);
        $isValidFormat = $date !== false && $date->format('Y-m-d') === $raw;

        return $isValidFormat ? $raw : null;
    }

    private function notFoundPage(): void
    {
        http_response_code(404);
        $this->view('errors/404', ['appName' => $this->config()['app']['name']]);
    }
}
