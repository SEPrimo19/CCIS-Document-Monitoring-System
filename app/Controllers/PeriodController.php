<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Guard;
use App\Models\AcademicPeriod;
use App\Models\AuditLog;
use PDOException;

/**
 * Administrator academic-period management (FR-29): create school year +
 * semester periods and designate which one is active.
 *
 * This is the control that makes the system self-sufficient across semesters.
 * Every other screen is scoped to the active period (FR-34) — the faculty
 * checklist, the review queue, the monitoring board, the dashboards — so
 * without it, rolling over to a new semester would need a developer running
 * SQL by hand.
 *
 * Periods are never deleted: requirements reference period_id, and submissions
 * reach back to a period through their requirement, so a delete would orphan
 * the historical record the archive (FR-33) exists to show. Closing a period
 * means deactivating it, which moves it into the archive.
 */
final class PeriodController extends Controller
{
    public function index(): void
    {
        Guard::requireRole('Secretary');

        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        $this->view('admin/periods/index', [
            'appName' => $this->config()['app']['name'],
            'periods' => AcademicPeriod::allWithCounts(),
            'flash'   => $flash,
            'csrf'    => Csrf::token(),
        ]);
    }

    public function create(): void
    {
        Guard::requireRole('Secretary');

        $this->renderForm(null, $this->emptyInput(), []);
    }

    public function store(): void
    {
        Guard::requireRole('Secretary');

        $input = $this->inputFrom($_POST);
        $token = (string) ($_POST['csrf_token'] ?? '');

        if (!Csrf::verify($token)) {
            $this->renderForm(null, $input, ['_form' => 'Your session has expired. Please try again.']);
            return;
        }

        $errors = $this->validate($input, null);
        if ($errors !== []) {
            $this->renderForm(null, $input, $errors);
            return;
        }

        $adminId = (int) (Auth::user()['user_id'] ?? 0);
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        $newId = AcademicPeriod::create(
            $input['school_year'],
            $input['semester'],
            $this->nullable($input['label']),
            $this->nullable($input['start_date']),
            $this->nullable($input['end_date'])
        );

        $label = $this->describe($input['school_year'], $input['semester']);
        $this->audit($adminId, 'period_create', $newId, $label, $ip);

        // Created inactive on purpose: publishing requirements against a period
        // the administrator has not finished setting up would immediately
        // change what every faculty member sees.
        $this->flash('ok', 'Academic period "' . $label . '" was created. Activate it when you are ready for faculty to see it.');
        $this->redirectToIndex();
    }

    public function edit(string $id): void
    {
        Guard::requireRole('Secretary');

        $period = AcademicPeriod::find((int) $id);
        if ($period === null) {
            $this->notFoundPage();
            return;
        }

        $this->renderForm($period, [
            'school_year' => $period['school_year'],
            'semester'    => $period['semester'],
            'label'       => (string) ($period['label'] ?? ''),
            'start_date'  => (string) ($period['start_date'] ?? ''),
            'end_date'    => (string) ($period['end_date'] ?? ''),
        ], []);
    }

    public function update(string $id): void
    {
        Guard::requireRole('Secretary');

        $periodId = (int) $id;
        $period = AcademicPeriod::find($periodId);
        if ($period === null) {
            $this->notFoundPage();
            return;
        }

        $input = $this->inputFrom($_POST);
        $token = (string) ($_POST['csrf_token'] ?? '');

        if (!Csrf::verify($token)) {
            $this->renderForm($period, $input, ['_form' => 'Your session has expired. Please try again.']);
            return;
        }

        $errors = $this->validate($input, $periodId);
        if ($errors !== []) {
            $this->renderForm($period, $input, $errors);
            return;
        }

        AcademicPeriod::update(
            $periodId,
            $input['school_year'],
            $input['semester'],
            $this->nullable($input['label']),
            $this->nullable($input['start_date']),
            $this->nullable($input['end_date'])
        );

        $adminId = (int) (Auth::user()['user_id'] ?? 0);
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $label = $this->describe($input['school_year'], $input['semester']);
        $this->audit($adminId, 'period_update', $periodId, $label, $ip);

        $this->flash('ok', 'Academic period "' . $label . '" was updated.');
        $this->redirectToIndex();
    }

    public function activate(string $id): void
    {
        Guard::requireRole('Secretary');

        $periodId = (int) $id;
        $period = AcademicPeriod::find($periodId);
        if ($period === null) {
            $this->notFoundPage();
            return;
        }

        if (!$this->verifyPostToken()) {
            return;
        }

        try {
            $activated = AcademicPeriod::activate($periodId);
        } catch (PDOException $e) {
            // The activate transaction locks every currently-active row; a
            // concurrent activate can lose a deadlock. Transient, so ask for a
            // retry rather than surfacing a 500.
            error_log('[CCIS-DMS] period activate failed for period ' . $periodId . ': ' . $e->getMessage());
            $this->flash('err', 'Could not switch the active period just now. Please try again.');
            $this->redirectToIndex();
            return;
        }

        if (!$activated) {
            $this->flash('err', 'That academic period no longer exists.');
            $this->redirectToIndex();
            return;
        }

        $adminId = (int) (Auth::user()['user_id'] ?? 0);
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $label = $this->describe($period['school_year'], $period['semester']);
        $this->audit($adminId, 'period_activate', $periodId, $label, $ip);

        $this->flash('ok', '"' . $label . '" is now the active academic period. Earlier periods moved to the archive.');
        $this->redirectToIndex();
    }

    public function deactivate(string $id): void
    {
        Guard::requireRole('Secretary');

        $periodId = (int) $id;
        $period = AcademicPeriod::find($periodId);
        if ($period === null) {
            $this->notFoundPage();
            return;
        }

        if (!$this->verifyPostToken()) {
            return;
        }

        $label = $this->describe($period['school_year'], $period['semester']);

        if (!AcademicPeriod::deactivate($periodId)) {
            // Already closed (a double-submit, or another admin closed it first)
            // — say so rather than flashing a success that did nothing.
            $this->flash('err', '"' . $label . '" was already closed.');
            $this->redirectToIndex();
            return;
        }

        $adminId = (int) (Auth::user()['user_id'] ?? 0);
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $this->audit($adminId, 'period_deactivate', $periodId, $label, $ip);

        $this->flash('ok', '"' . $label . '" was closed. No period is active — activate one when the next semester begins.');
        $this->redirectToIndex();
    }

    /**
     * Best-effort audit write: an audit-log failure must never turn a period
     * change that already succeeded into a 500 (mirrors the same pattern in the
     * reviewer and upload flows).
     */
    private function audit(int $userId, string $action, int $entityId, string $detail, string $ip): void
    {
        try {
            AuditLog::record($userId, $action, 'academic_period', $entityId, $detail, $ip);
        } catch (\Throwable $e) {
            error_log('[CCIS-DMS] audit write failed (' . $action . ' period ' . $entityId . '): ' . $e->getMessage());
        }
    }

    /**
     * CSRF check shared by the two POST-only status actions. Flashes and
     * redirects itself; returns false when the caller should stop.
     */
    private function verifyPostToken(): bool
    {
        if (Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
            return true;
        }

        $this->flash('err', 'Invalid request. Please try again.');
        $this->redirectToIndex();

        return false;
    }

    /**
     * @return array{school_year:string,semester:string,label:string,start_date:string,end_date:string}
     */
    private function emptyInput(): array
    {
        return ['school_year' => '', 'semester' => '', 'label' => '', 'start_date' => '', 'end_date' => ''];
    }

    /**
     * @return array{school_year:string,semester:string,label:string,start_date:string,end_date:string}
     */
    private function inputFrom(array $post): array
    {
        return [
            'school_year' => trim((string) ($post['school_year'] ?? '')),
            'semester'    => trim((string) ($post['semester'] ?? '')),
            'label'       => trim((string) ($post['label'] ?? '')),
            'start_date'  => trim((string) ($post['start_date'] ?? '')),
            'end_date'    => trim((string) ($post['end_date'] ?? '')),
        ];
    }

    private function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    private function describe(string $schoolYear, string $semester): string
    {
        return 'AY ' . $schoolYear . ', ' . $semester . ' Semester';
    }

    /**
     * @param array{school_year:string,semester:string,label:string,start_date:string,end_date:string} $input
     * @return array<string,string> field => error message; empty when valid.
     */
    private function validate(array $input, ?int $exceptId): array
    {
        $errors = [];

        // school_year is VARCHAR(9): exactly "YYYY-YYYY", and the second year
        // must follow the first — "2026-2029" is a typo, not a school year.
        if ($input['school_year'] === '') {
            $errors['school_year'] = 'School year is required.';
        } elseif (preg_match('/^(\d{4})-(\d{4})$/', $input['school_year'], $m) !== 1) {
            $errors['school_year'] = 'Use the format YYYY-YYYY, for example 2026-2027.';
        } elseif ((int) $m[2] !== (int) $m[1] + 1) {
            $errors['school_year'] = 'The second year must follow the first, for example 2026-2027.';
        }

        if ($input['semester'] === '') {
            $errors['semester'] = 'Semester is required.';
        } elseif (!in_array($input['semester'], AcademicPeriod::semesters(), true)) {
            $errors['semester'] = 'Select a valid semester.';
        }

        // Only worth checking the unique (school_year, semester) pair once both
        // halves are individually valid.
        if (!isset($errors['school_year'])
            && !isset($errors['semester'])
            && AcademicPeriod::existsByYearSemester($input['school_year'], $input['semester'], $exceptId)
        ) {
            $errors['semester'] = 'That school year and semester already exist as a period.';
        }

        if (mb_strlen($input['label']) > 60) {
            $errors['label'] = 'Label must be 60 characters or fewer.';
        }

        $start = $this->validDate($input['start_date']);
        if ($input['start_date'] !== '' && $start === null) {
            $errors['start_date'] = 'Enter a valid date.';
        }

        $end = $this->validDate($input['end_date']);
        if ($input['end_date'] !== '' && $end === null) {
            $errors['end_date'] = 'Enter a valid date.';
        }

        if ($start !== null && $end !== null && $end < $start) {
            $errors['end_date'] = 'The end date cannot be before the start date.';
        }

        return $errors;
    }

    /**
     * A real YYYY-MM-DD calendar date, or null. checkdate() rejects the values
     * strtotime() would silently roll over (2026-02-30 becoming March 2nd).
     */
    private function validDate(string $value): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null;
    }

    /**
     * @param array{period_id:int,school_year:string,semester:string,label:?string,start_date:?string,end_date:?string,is_active:int}|null $period
     * @param array{school_year:string,semester:string,label:string,start_date:string,end_date:string} $input
     * @param array<string,string> $errors
     */
    private function renderForm(?array $period, array $input, array $errors): void
    {
        $this->view('admin/periods/form', [
            'appName'   => $this->config()['app']['name'],
            'period'    => $period,
            'input'     => $input,
            'semesters' => AcademicPeriod::semesters(),
            'errors'    => $errors,
            'csrf'      => Csrf::token(),
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

    private function redirectToIndex(): void
    {
        header('Location: ' . url('/admin/periods'));
        exit;
    }
}
