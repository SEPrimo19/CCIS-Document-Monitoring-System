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
use App\Models\Program;
use App\Models\Requirement;
use App\Models\Submission;
use App\Models\User;
use DateTime;
use Throwable;

/**
 * Administrator requirement publishing (FR-28) with audience targeting
 * (FR-35). Publishing is EAGER: as soon as a requirement is created, a Pending
 * submission is generated for every faculty member in its audience. The target
 * period is always the single active academic period — there is no period
 * selector.
 *
 * The audience is one of three kinds, chosen on the form:
 *   'all_faculty' — every active Faculty account (the default, and the only
 *                   behaviour this controller had before FR-35).
 *   'program'     — every active Faculty account in one program.
 *   'individual'  — the faculty members the Secretary ticked.
 *
 * The posted audience is never taken at face value. resolveAudience() turns it
 * into a faculty-id list by asking the database which of those accounts are
 * really active and really Faculty (User::activeFacultyIdsForProgram() /
 * User::filterActiveFacultyIds()), so a hand-crafted POST naming the
 * Secretary, an inactive account, or a retired program cannot create
 * submission rows.
 */
final class RequirementController extends Controller
{
    /** The requirements.applies_to ENUM values. */
    private const AUDIENCES = ['all_faculty', 'program', 'individual'];

    public function index(): void
    {
        Guard::requireRole('Secretary');

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
        Guard::requireRole('Secretary');

        $period = AcademicPeriod::active();
        $docTypes = $this->activeDocumentTypes();

        $this->renderForm($docTypes, $period, [
            'doc_type_id'       => '',
            'title'             => '',
            'description'       => '',
            'deadline'          => '',
            'applies_to'        => 'all_faculty',
            'target_program_id' => '',
            'target_faculty'    => [],
        ], []);
    }

    public function store(): void
    {
        Guard::requireRole('Secretary');

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

        // Authoritative audience resolution: re-derived from the database, not
        // from the POST. Runs after validation so the form has already proved
        // the *shape* of the audience; this decides who is actually in it.
        $facultyIds = $this->resolveAudience($input);

        $adminId = (int) (Auth::user()['user_id'] ?? 0);
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $description = $this->nullableDescription($input['description']);
        $targetProgramId = $input['applies_to'] === 'program' ? (int) $input['target_program_id'] : null;

        $pdo = Database::connection($this->config()['db']);
        $pdo->beginTransaction();

        try {
            $requirementId = Requirement::create(
                (int) $input['doc_type_id'],
                (int) $period['period_id'],
                $input['title'],
                $description,
                $input['deadline'],
                $adminId,
                $input['applies_to'],
                $targetProgramId
            );

            // The individually-picked audience is stored, not just applied:
            // Submission::backfillForFaculty() re-reads it for faculty added
            // after this publish. Inside the same transaction as the
            // requirement row, so a requirement can never exist with a
            // half-written target list.
            if ($input['applies_to'] === 'individual') {
                Requirement::addTargets($requirementId, $facultyIds);
            }

            $publishedCount = Submission::createPendingForFaculty($requirementId, $facultyIds);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // Best-effort, post-commit: tell every assigned faculty member they
        // have a new requirement to submit (FR-21). Scoped to the audience for
        // free — it notifies the owner of each Pending submission this publish
        // just created, so a program/individual requirement never reaches
        // faculty outside it. Must never undo a publish that already
        // committed: a notification failure just means the item still appears
        // on their checklist without the heads-up.
        try {
            $message = mb_substr(
                '"' . $input['title'] . '" has been assigned to you. Due '
                . date('M j, Y', strtotime($input['deadline'])) . '. Please upload your document.',
                0,
                255
            );
            Notification::createRequirementAssigned($requirementId, $message);
        } catch (Throwable $e) {
            error_log('[CCIS-DMS] requirement-assigned notification failed for requirement ' . $requirementId . ': ' . $e->getMessage());
        }

        // Best-effort, post-commit: an audit-log write must never 500 a
        // requirement that already published successfully.
        try {
            AuditLog::record(
                $adminId,
                'requirement_publish',
                'requirement',
                $requirementId,
                mb_substr($input['title'] . '; ' . $this->audienceLabel($input) . '; ' . $publishedCount . ' faculty', 0, 255),
                $ip
            );
        } catch (Throwable $e) {
            error_log('[CCIS-DMS] audit log write failed for requirement ' . $requirementId . ': ' . $e->getMessage());
        }

        // A program with no active faculty in it publishes zero submissions.
        // That is a legitimate thing to ask for and the requirement IS saved —
        // a faculty member assigned to the program later picks it up via
        // Submission::backfillForFaculty() — but from the Secretary's side it
        // looks identical to a no-op, so warn instead of reporting success.
        $this->flash(
            $publishedCount === 0 ? 'err' : 'ok',
            $publishedCount === 0
                ? 'Requirement saved, but no active faculty currently match its audience ('
                    . $this->audienceLabel($input) . ') — no submissions were created. '
                    . 'Faculty added to it later will pick this requirement up automatically.'
                : 'Requirement published to ' . $publishedCount . ' faculty (' . $this->audienceLabel($input) . ').'
        );
        $this->redirectToIndex();
    }

    /**
     * Turn a validated audience into the faculty-id list to publish against.
     *
     * Every arm re-checks role and active status in SQL. Nothing that arrives
     * in the POST body is used as an id without the database confirming it
     * names an active Faculty account first.
     *
     * @param array{applies_to:string,target_program_id:string,target_faculty:list<string>} $input
     * @return list<int>
     */
    private function resolveAudience(array $input): array
    {
        if ($input['applies_to'] === 'program') {
            return User::activeFacultyIdsForProgram((int) $input['target_program_id']);
        }

        if ($input['applies_to'] === 'individual') {
            return User::filterActiveFacultyIds(array_map('intval', $input['target_faculty']));
        }

        return User::activeFacultyIds();
    }

    /**
     * Human-readable audience, for the flash message and the audit-log detail.
     *
     * @param array{applies_to:string,target_program_id:string,target_faculty:list<string>} $input
     */
    private function audienceLabel(array $input): string
    {
        if ($input['applies_to'] === 'program') {
            $program = Program::find((int) $input['target_program_id']);

            return 'program ' . ($program !== null ? $program['code'] : (string) $input['target_program_id']);
        }

        if ($input['applies_to'] === 'individual') {
            return 'selected faculty';
        }

        return 'all faculty';
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
     * @return array{doc_type_id:string,title:string,description:string,deadline:string,applies_to:string,target_program_id:string,target_faculty:list<string>}
     */
    private function inputFrom(array $post): array
    {
        // target_faculty[] is a checkbox group: absent entirely when nothing is
        // ticked, and a scalar rather than an array if somebody posts
        // "target_faculty=3" by hand. Normalize both to a list of strings so
        // the rest of the controller has one shape to reason about.
        $targets = $post['target_faculty'] ?? [];
        if (!is_array($targets)) {
            $targets = [$targets];
        }

        return [
            'doc_type_id'       => trim((string) ($post['doc_type_id'] ?? '')),
            'title'             => trim((string) ($post['title'] ?? '')),
            'description'       => trim((string) ($post['description'] ?? '')),
            'deadline'          => trim((string) ($post['deadline'] ?? '')),
            'applies_to'        => trim((string) ($post['applies_to'] ?? 'all_faculty')),
            'target_program_id' => trim((string) ($post['target_program_id'] ?? '')),
            'target_faculty'    => array_values(array_map(
                static fn($id): string => trim((string) (is_scalar($id) ? $id : '')),
                $targets
            )),
        ];
    }

    private function nullableDescription(string $description): ?string
    {
        return $description === '' ? null : $description;
    }

    /**
     * @param array{doc_type_id:string,title:string,description:string,deadline:string,applies_to:string,target_program_id:string,target_faculty:list<string>} $input
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

        return array_merge($errors, $this->validateAudience($input));
    }

    /**
     * Audience validation (FR-35), server-side and authoritative.
     *
     * The form always submits all three controls (they must keep working with
     * JavaScript off, where nothing is hidden), so the payload for the two
     * audience kinds that were NOT chosen is simply ignored here rather than
     * treated as an error — the radio decides which one counts.
     *
     * @param array{applies_to:string,target_program_id:string,target_faculty:list<string>} $input
     * @return array<string,string>
     */
    private function validateAudience(array $input): array
    {
        if (!in_array($input['applies_to'], self::AUDIENCES, true)) {
            return ['applies_to' => 'Select who this requirement applies to.'];
        }

        if ($input['applies_to'] === 'program') {
            if ($input['target_program_id'] === '') {
                return ['target_program_id' => 'Select a program.'];
            }
            // Re-checked against the database, not the rendered <option> list:
            // the form could be stale, or forged outright.
            if (!ctype_digit($input['target_program_id']) || !Program::isActive((int) $input['target_program_id'])) {
                return ['target_program_id' => 'Select a valid, active program.'];
            }
        }

        if ($input['applies_to'] === 'individual') {
            $posted = array_values(array_filter(
                $input['target_faculty'],
                static fn(string $id): bool => $id !== '' && ctype_digit($id)
            ));

            if ($posted === []) {
                return ['target_faculty' => 'Select at least one faculty member.'];
            }

            // Not "are these ids well-formed" but "are these ids real, active
            // Faculty accounts". The check is on the DE-DUPLICATED posted set,
            // because filterActiveFacultyIds() de-duplicates too and a repeated
            // checkbox value must not read as a missing one.
            $unique = array_values(array_unique(array_map('intval', $posted)));
            $valid = User::filterActiveFacultyIds($unique);

            if ($valid === []) {
                return ['target_faculty' => 'Select at least one active faculty member.'];
            }

            // Refuse the whole publish if ANY posted id failed that check,
            // rather than quietly publishing to the subset that passed. The UI
            // cannot produce a bad id, so this fires on two things worth
            // surfacing: a forged POST, and a stale form (someone deactivated
            // an account while this page was open). Silently narrowing the
            // audience would leave the Secretary believing they had assigned
            // the requirement to people who never received it.
            if (count($valid) !== count($unique)) {
                return ['target_faculty' => 'One or more selected accounts are no longer active Faculty accounts. Reload this page and choose again.'];
            }
        }

        return [];
    }

    /**
     * @param list<array{doc_type_id:int,name:string,description:?string,is_active:int,created_by:?int,created_at:string}> $docTypes
     * @param array{period_id:int,school_year:string,semester:string,label:?string,start_date:?string,end_date:?string,is_active:int}|null $period
     * @param array{doc_type_id:string,title:string,description:string,deadline:string,applies_to:string,target_program_id:string,target_faculty:list<string>} $input
     * @param array<string,string> $errors
     */
    private function renderForm(array $docTypes, ?array $period, array $input, array $errors): void
    {
        $this->view('admin/requirements/form', [
            'appName'  => $this->config()['app']['name'],
            'docTypes' => $docTypes,
            'period'   => $period,
            'programs' => Program::allActive(),
            'faculty'  => User::activeFacultyForPicker(),
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
