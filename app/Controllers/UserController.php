<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Guard;
use App\Models\AuditLog;
use App\Models\Program;
use App\Models\Role;
use App\Models\User;
use PDOException;

/**
 * Secretary user management (FR-26): list, create, edit, and
 * deactivate/reactivate accounts, and assign roles and academic programs
 * (FR-36). Deactivation is a soft-delete (status), never a row delete, since
 * audit_log and submissions reference user_id.
 *
 * Program assignment lives HERE rather than on the self-service profile screen
 * on purpose. Requirement audiences can target a program (FR-35), so a faculty
 * member able to set their own program could move themselves out of a
 * requirement aimed at it. The column is therefore Secretary-only, guarded by
 * the same requireRole('Secretary') as every other action on this controller,
 * and ProfileController no longer reads a program field at all.
 *
 * Two safety rules are enforced on every mutation that touches role or
 * status, not just in the UI:
 *   1. No self-lockout — a Secretary may not deactivate their own account,
 *      nor change their own role away from Secretary.
 *   2. Preserve the last Secretary — the last remaining ACTIVE Secretary may
 *      never be deactivated or demoted, regardless of who is acting. With only
 *      two roles, that account is the sole way into user management, so losing
 *      it would lock the college out of its own system.
 */
final class UserController extends Controller
{
    public function index(): void
    {
        Guard::requireRole('Secretary');

        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        $this->view('admin/users/index', [
            'appName'       => $this->config()['app']['name'],
            'users'         => User::all(),
            'currentUserId' => (int) (Auth::user()['user_id'] ?? 0),
            'flash'         => $flash,
            'csrf'          => Csrf::token(),
        ]);
    }

    public function create(): void
    {
        Guard::requireRole('Secretary');

        $this->renderForm(null, Role::all(), $this->emptyInput(), []);
    }

    public function store(): void
    {
        Guard::requireRole('Secretary');

        $roles = Role::all();
        $input = $this->inputFrom($_POST);
        $token = (string) ($_POST['csrf_token'] ?? '');

        if (!Csrf::verify($token)) {
            $this->renderForm(null, $roles, $input, ['_form' => 'Your session has expired. Please try again.']);
            return;
        }

        $errors = $this->validate($input, $roles, null, true);
        if ($errors !== []) {
            $this->renderForm(null, $roles, $input, $errors);
            return;
        }

        $email = Auth::normalizeEmail($input['email']);
        $passwordHash = password_hash($input['password'], PASSWORD_BCRYPT);

        $newId = User::create(
            $input['first_name'],
            $input['last_name'],
            $email,
            (int) $input['role_id'],
            $passwordHash,
            $this->programIdFor($input)
        );

        $adminId = (int) (Auth::user()['user_id'] ?? 0);
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        AuditLog::record($adminId, 'user_create', 'user', $newId, $email, $ip);

        $this->flash('ok', 'User "' . $input['first_name'] . ' ' . $input['last_name'] . '" was created.');
        $this->redirectToIndex();
    }

    public function edit(string $id): void
    {
        Guard::requireRole('Secretary');

        $target = User::find((int) $id);
        if ($target === null) {
            $this->notFoundPage();
            return;
        }

        $this->renderForm($target, Role::all(), [
            'first_name' => $target['first_name'],
            'last_name'  => $target['last_name'],
            'email'      => $target['email'],
            'role_id'    => (string) $target['role_id'],
            'program_id' => $target['program_id'] === null ? '' : (string) $target['program_id'],
            'password'   => '',
        ], []);
    }

    public function update(string $id): void
    {
        Guard::requireRole('Secretary');

        $userId = (int) $id;
        $target = User::find($userId);
        if ($target === null) {
            $this->notFoundPage();
            return;
        }

        $roles = Role::all();
        $input = $this->inputFrom($_POST);
        $token = (string) ($_POST['csrf_token'] ?? '');

        if (!Csrf::verify($token)) {
            $this->renderForm($target, $roles, $input, ['_form' => 'Your session has expired. Please try again.']);
            return;
        }

        $errors = $this->validate($input, $roles, $userId, false);

        $currentUserId = (int) (Auth::user()['user_id'] ?? 0);
        $adminRoleId = $this->administratorRoleId($roles);

        if ($errors === [] && ctype_digit($input['role_id'])) {
            $roleError = $this->roleSafetyError($target, $currentUserId, (int) $input['role_id'], $adminRoleId);
            if ($roleError !== null) {
                $errors['role_id'] = $roleError;
            }
        }

        if ($errors !== []) {
            $this->renderForm($target, $roles, $input, $errors);
            return;
        }

        $email = Auth::normalizeEmail($input['email']);

        // The roleSafetyError pre-check above gives the friendly message in the
        // common case but reads the admin count non-atomically. When this update
        // demotes another active Secretary, route it through the
        // transactional guard so a concurrent demotion cannot race it to zero
        // admins (TOCTOU); a false return means that guard refused the demotion.
        $guardLastAdmin = $adminRoleId !== null
            && (int) $target['role_id'] === $adminRoleId
            && (int) $input['role_id'] !== $adminRoleId
            && $target['status'] === 'active'
            && (int) $target['user_id'] !== $currentUserId;

        // The guard takes row locks, so a concurrent admin edit can lose a
        // deadlock or hit the lock-wait timeout. That is a transient DB
        // condition, not a bug in the submitted data — show a retry message
        // with the form still filled in, rather than a raw 500.
        try {
            $saved = User::updateProfileGuardingLastAdmin(
                $userId,
                $input['first_name'],
                $input['last_name'],
                $email,
                (int) $input['role_id'],
                $this->programIdFor($input),
                $guardLastAdmin
            );
        } catch (PDOException $e) {
            error_log('[CCIS-DMS] user update failed for user ' . $userId . ': ' . $e->getMessage());
            $errors['_form'] = 'Could not save this user just now — another change may have been in progress. Please try again.';
            $this->renderForm($target, $roles, $input, $errors);
            return;
        }

        if (!$saved) {
            $errors['role_id'] = 'At least one active Secretary account must remain. Assign another user the Secretary role first.';
            $this->renderForm($target, $roles, $input, $errors);
            return;
        }

        if ($input['password'] !== '') {
            User::updatePasswordHash($userId, password_hash($input['password'], PASSWORD_BCRYPT));
        }

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        AuditLog::record($currentUserId, 'user_update', 'user', $userId, $email, $ip);

        $this->flash('ok', 'User "' . $input['first_name'] . ' ' . $input['last_name'] . '" was updated.');
        $this->redirectToIndex();
    }

    public function deactivate(string $id): void
    {
        Guard::requireRole('Secretary');

        $this->toggleActive($id, false, 'user_deactivate');
    }

    public function activate(string $id): void
    {
        Guard::requireRole('Secretary');

        $this->toggleActive($id, true, 'user_activate');
    }

    private function toggleActive(string $id, bool $active, string $action): void
    {
        $userId = (int) $id;
        $target = User::find($userId);
        if ($target === null) {
            $this->notFoundPage();
            return;
        }

        $token = (string) ($_POST['csrf_token'] ?? '');
        if (!Csrf::verify($token)) {
            $this->flash('err', 'Invalid request. Please try again.');
            $this->redirectToIndex();
            return;
        }

        $currentUserId = (int) (Auth::user()['user_id'] ?? 0);

        if (!$active) {
            // Safety rule 1: no self-lockout (same session — race-free).
            if ($userId === $currentUserId) {
                $this->flash('err', 'You cannot deactivate your own account.');
                $this->redirectToIndex();
                return;
            }

            // Safety rule 2: preserve the last active Secretary. For an
            // active admin this goes through the atomic guard so concurrent
            // deactivations cannot race past a stale count (TOCTOU-safe);
            // anything else is a plain status flip.
            if ($target['role_name'] === 'Secretary' && $target['status'] === 'active') {
                try {
                    $deactivated = User::deactivateGuardingLastAdmin($userId);
                } catch (PDOException $e) {
                    // Transient DB contention (deadlock / lock-wait timeout)
                    // on the guard's row locks — a retry message, not a 500.
                    error_log('[CCIS-DMS] deactivate failed for user ' . $userId . ': ' . $e->getMessage());
                    $this->flash('err', 'Could not deactivate this account just now — another change may have been in progress. Please try again.');
                    $this->redirectToIndex();
                    return;
                }

                if (!$deactivated) {
                    // Zero rows matched means one of two different things: the
                    // guard refused (this really is the last active admin), or
                    // another admin deactivated the account between our read
                    // and this UPDATE. Re-read so the message says which —
                    // otherwise a raced deactivation reports a last-admin
                    // problem that doesn't exist.
                    $fresh = User::find($userId);
                    $this->flash('err', $fresh !== null && $fresh['status'] === 'inactive'
                        ? 'That account had already been deactivated by someone else.'
                        : 'At least one active Secretary account must remain. Assign another Secretary before deactivating this one.');
                    $this->redirectToIndex();
                    return;
                }
            } else {
                User::setActive($userId, false);
            }
        } else {
            User::setActive($userId, true);
        }

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        AuditLog::record($currentUserId, $action, 'user', $userId, $target['email'], $ip);

        $this->flash('ok', 'User "' . $target['first_name'] . ' ' . $target['last_name'] . '" was ' . ($active ? 'reactivated' : 'deactivated') . '.');
        $this->redirectToIndex();
    }

    /**
     * The posted program_id as it should be stored: null when blank (the
     * Secretary and any unassigned account carry no program), otherwise the
     * validated id. Only ever called after validate() has confirmed the id
     * names a real, ACTIVE program, so a retired program cannot be assigned
     * afresh even though existing rows keep pointing at it.
     *
     * @param array{program_id:string} $input
     */
    private function programIdFor(array $input): ?int
    {
        return $input['program_id'] === '' ? null : (int) $input['program_id'];
    }

    /**
     * @return array{first_name:string,last_name:string,email:string,role_id:string,program_id:string,password:string}
     */
    private function emptyInput(): array
    {
        return ['first_name' => '', 'last_name' => '', 'email' => '', 'role_id' => '', 'program_id' => '', 'password' => ''];
    }

    /**
     * @return array{first_name:string,last_name:string,email:string,role_id:string,program_id:string,password:string}
     */
    private function inputFrom(array $post): array
    {
        return [
            'first_name' => trim((string) ($post['first_name'] ?? '')),
            'last_name'  => trim((string) ($post['last_name'] ?? '')),
            'email'      => trim((string) ($post['email'] ?? '')),
            'role_id'    => trim((string) ($post['role_id'] ?? '')),
            'program_id' => trim((string) ($post['program_id'] ?? '')),
            // Not trimmed: leading/trailing spaces in a password are legitimate characters.
            'password'   => (string) ($post['password'] ?? ''),
        ];
    }

    /**
     * @param array{first_name:string,last_name:string,email:string,role_id:string,program_id:string,password:string} $input
     * @param list<array{role_id:int,role_name:string}> $roles
     * @return array<string,string> field => error message; empty when valid.
     */
    private function validate(array $input, array $roles, ?int $exceptId, bool $passwordRequired): array
    {
        $errors = [];

        $firstLength = mb_strlen($input['first_name']);
        if ($input['first_name'] === '') {
            $errors['first_name'] = 'First name is required.';
        } elseif ($firstLength > 60) {
            $errors['first_name'] = 'First name must be 60 characters or fewer.';
        }

        $lastLength = mb_strlen($input['last_name']);
        if ($input['last_name'] === '') {
            $errors['last_name'] = 'Last name is required.';
        } elseif ($lastLength > 60) {
            $errors['last_name'] = 'Last name must be 60 characters or fewer.';
        }

        // Length is bounded to the column width (VARCHAR(120)) BEFORE the format
        // check, so an over-long address is rejected outright rather than
        // silently truncated on write (which could also collide the unique key).
        if ($input['email'] === '') {
            $errors['email'] = 'Email is required.';
        } elseif (mb_strlen($input['email']) > 120) {
            $errors['email'] = 'Email must be 120 characters or fewer.';
        } elseif (filter_var($input['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Enter a valid email address.';
        } elseif (User::existsByEmail(Auth::normalizeEmail($input['email']), $exceptId)) {
            $errors['email'] = 'A user with this email already exists.';
        }

        $validRoleIds = array_map(static fn(array $role): int => (int) $role['role_id'], $roles);
        if ($input['role_id'] === '') {
            $errors['role_id'] = 'Role is required.';
        } elseif (!ctype_digit($input['role_id']) || !in_array((int) $input['role_id'], $validRoleIds, true)) {
            $errors['role_id'] = 'Select a valid role.';
        }

        // Program is OPTIONAL: the Secretary belongs to the office, not to a
        // program, and a faculty account may legitimately be created before its
        // program is known. When one IS given it is re-checked against the
        // database rather than against the rendered <option> list, since the
        // form could be stale or forged.
        if ($input['program_id'] !== '') {
            if (!ctype_digit($input['program_id']) || !Program::isActive((int) $input['program_id'])) {
                $errors['program_id'] = 'Select a valid, active program.';
            }
        }

        $passwordLength = strlen($input['password']);
        if ($passwordRequired && $input['password'] === '') {
            $errors['password'] = 'Password is required.';
        } elseif ($input['password'] !== '') {
            if ($passwordLength < 8 || $passwordLength > 72) {
                $errors['password'] = 'Password must be between 8 and 72 characters.';
            } elseif (trim($input['password']) === '') {
                $errors['password'] = 'Password cannot be only spaces.';
            }
        }

        return $errors;
    }

    /**
     * @param list<array{role_id:int,role_name:string}> $roles
     */
    private function administratorRoleId(array $roles): ?int
    {
        foreach ($roles as $role) {
            if ($role['role_name'] === 'Secretary') {
                return (int) $role['role_id'];
            }
        }

        return null;
    }

    /**
     * Enforces both role-change safety rules. Only fires when the target's
     * CURRENT role is Secretary and the submitted role_id would actually
     * change it to something else — a no-op role change is never blocked by
     * this method.
     *
     * @param array{user_id:int,first_name:string,last_name:string,email:string,role_id:int,role_name:string,program_id:?int,status:string} $target
     */
    private function roleSafetyError(array $target, int $currentUserId, int $newRoleId, ?int $adminRoleId): ?string
    {
        if ($adminRoleId === null || (int) $target['role_id'] !== $adminRoleId || $newRoleId === $adminRoleId) {
            return null;
        }

        if ((int) $target['user_id'] === $currentUserId) {
            return 'You cannot change your own role away from Secretary.';
        }

        if ($target['status'] === 'active' && User::activeAdminCount() <= 1) {
            return 'At least one active Secretary account must remain. Assign another user the Secretary role first.';
        }

        return null;
    }

    /**
     * @param array{user_id:int,first_name:string,last_name:string,email:string,role_id:int,role_name:string,program_id:?int,status:string}|null $target
     * @param list<array{role_id:int,role_name:string}> $roles
     * @param array{first_name:string,last_name:string,email:string,role_id:string,program_id:string,password:string} $input
     * @param array<string,string> $errors
     */
    private function renderForm(?array $target, array $roles, array $input, array $errors): void
    {
        $this->view('admin/users/form', [
            'appName'  => $this->config()['app']['name'],
            'target'   => $target,
            'roles'    => $roles,
            'programs' => Program::allActive(),
            'input'    => $input,
            'errors'   => $errors,
            'csrf'     => Csrf::token(),
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
        header('Location: ' . url('/admin/users'));
        exit;
    }
}
