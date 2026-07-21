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
use App\Models\Requirement;
use App\Models\Submission;
use App\Models\User;
use DateTime;
use Throwable;

/**
 * Administrator requirement publishing (FR-28). Publishing is EAGER: as soon
 * as a requirement is created, a Pending submission is generated for every
 * active Faculty account. Audience is fixed to all-faculty and the target
 * period is always the single active academic period — no period selector
 * or program/individual targeting in this slice.
 */
final class RequirementController extends Controller
{
    public function index(): void
    {
        Guard::requireRole('Administrator');

        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        $period = AcademicPeriod::active();
        $requirements = $period !== null ? Requirement::allForPeriod((int) $period['period_id']) : [];

        $this->view('admin/requirements/index', [
            'appName'      => $this->config()['app']['name'],
            'period'       => $period,
            'requirements' => $requirements,
            'flash'        => $flash,
            'csrf'         => Csrf::token(),
        ]);
    }

    public function create(): void
    {
        Guard::requireRole('Administrator');

        $period = AcademicPeriod::active();
        $docTypes = $this->activeDocumentTypes();

        $this->renderForm($docTypes, $period, [
            'doc_type_id' => '',
            'title'       => '',
            'description' => '',
            'deadline'    => '',
        ], []);
    }

    public function store(): void
    {
        Guard::requireRole('Administrator');

        $period = AcademicPeriod::active();
        $docTypes = $this->activeDocumentTypes();
        $input = $this->inputFrom($_POST);

        if ($period === null || $docTypes === []) {
            $this->flash('err', 'Add an active document type and activate an academic period before publishing a requirement.');
            $this->redirectToIndex();
            return;
        }

        $token = (string) ($_POST['csrf_token'] ?? '');
        if (!Csrf::verify($token)) {
            $this->renderForm($docTypes, $period, $input, ['_csrf' => 'Your session has expired. Please try again.']);
            return;
        }

        $errors = $this->validate($input, $docTypes);
        if ($errors !== []) {
            $this->renderForm($docTypes, $period, $input, $errors);
            return;
        }

        $adminId = (int) (Auth::user()['user_id'] ?? 0);
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $description = $this->nullableDescription($input['description']);

        $pdo = Database::connection($this->config()['db']);
        $pdo->beginTransaction();

        try {
            $requirementId = Requirement::create(
                (int) $input['doc_type_id'],
                (int) $period['period_id'],
                $input['title'],
                $description,
                $input['deadline'],
                $adminId
            );

            $facultyIds = User::activeFacultyIds();
            $publishedCount = Submission::createPendingForFaculty($requirementId, $facultyIds);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        AuditLog::record(
            $adminId,
            'requirement_publish',
            'requirement',
            $requirementId,
            $input['title'] . '; ' . $publishedCount . ' faculty',
            $ip
        );

        $this->flash('ok', 'Requirement published to ' . $publishedCount . ' faculty.');
        $this->redirectToIndex();
    }

    /**
     * Active document types only — a requirement must reference an active
     * type, never a deactivated one.
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
     * @return array{doc_type_id:string,title:string,description:string,deadline:string}
     */
    private function inputFrom(array $post): array
    {
        return [
            'doc_type_id' => trim((string) ($post['doc_type_id'] ?? '')),
            'title'       => trim((string) ($post['title'] ?? '')),
            'description' => trim((string) ($post['description'] ?? '')),
            'deadline'    => trim((string) ($post['deadline'] ?? '')),
        ];
    }

    private function nullableDescription(string $description): ?string
    {
        return $description === '' ? null : $description;
    }

    /**
     * @param array{doc_type_id:string,title:string,description:string,deadline:string} $input
     * @param list<array{doc_type_id:int,name:string,description:?string,is_active:int,created_by:?int,created_at:string}> $activeDocTypes
     * @return array<string,string> field => error message; empty when valid.
     */
    private function validate(array $input, array $activeDocTypes): array
    {
        $errors = [];

        $titleLength = mb_strlen($input['title']);
        if ($input['title'] === '') {
            $errors['title'] = 'Title is required.';
        } elseif ($titleLength < 3 || $titleLength > 120) {
            $errors['title'] = 'Title must be between 3 and 120 characters.';
        }

        $validDocTypeIds = array_map(static fn(array $type): int => (int) $type['doc_type_id'], $activeDocTypes);
        if ($input['doc_type_id'] === '') {
            $errors['doc_type_id'] = 'Document type is required.';
        } elseif (!ctype_digit($input['doc_type_id']) || !in_array((int) $input['doc_type_id'], $validDocTypeIds, true)) {
            $errors['doc_type_id'] = 'Select a valid, active document type.';
        }

        if ($input['deadline'] === '') {
            $errors['deadline'] = 'Deadline is required.';
        } else {
            $date = DateTime::createFromFormat('Y-m-d', $input['deadline']);
            $isValidFormat = $date !== false && $date->format('Y-m-d') === $input['deadline'];
            if (!$isValidFormat) {
                $errors['deadline'] = 'Enter a valid date (YYYY-MM-DD).';
            } elseif ($input['deadline'] < date('Y-m-d')) {
                $errors['deadline'] = 'Deadline cannot be in the past.';
            }
        }

        if (mb_strlen($input['description']) > 255) {
            $errors['description'] = 'Description must be 255 characters or fewer.';
        }

        return $errors;
    }

    /**
     * @param list<array{doc_type_id:int,name:string,description:?string,is_active:int,created_by:?int,created_at:string}> $docTypes
     * @param array{period_id:int,school_year:string,semester:string,label:?string,start_date:?string,end_date:?string,is_active:int}|null $period
     * @param array{doc_type_id:string,title:string,description:string,deadline:string} $input
     * @param array<string,string> $errors
     */
    private function renderForm(array $docTypes, ?array $period, array $input, array $errors): void
    {
        $this->view('admin/requirements/form', [
            'appName'  => $this->config()['app']['name'],
            'docTypes' => $docTypes,
            'period'   => $period,
            'input'    => $input,
            'errors'   => $errors,
            'csrf'     => Csrf::token(),
        ]);
    }

    private function flash(string $type, string $message): void
    {
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    }

    private function redirectToIndex(): void
    {
        header('Location: ' . url('/admin/requirements'));
        exit;
    }
}
