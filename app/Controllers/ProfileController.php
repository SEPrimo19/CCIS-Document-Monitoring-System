<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Guard;
use App\Models\AuditLog;
use App\Models\User;

/**
 * Self-service profile and password change (FR-5), for every authenticated
 * user regardless of role.
 *
 * Two rules shape this controller:
 *
 *  1. **Nothing here can escalate privilege.** Role and account status are not
 *     editable, and User::updateOwnProfile()'s statement does not even name
 *     those columns — so a forged role_id in the POST body has nothing to bind
 *     to. The user id always comes from the session, never from the request.
 *
 *  2. **A password change must prove knowledge of the current password.** An
 *     unattended signed-in browser must not be enough to lock the real owner
 *     out of their account, so the current password is verified with
 *     password_verify() before the new hash is written.
 */
final class ProfileController extends Controller
{
    public function show(): void
    {
        Guard::requireAuth();

        $profile = $this->currentProfile();
        if ($profile === null) {
            return;
        }

        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        $this->render($profile, $this->inputFromProfile($profile), [], [], $flash);
    }

    public function update(): void
    {
        Guard::requireAuth();

        $profile = $this->currentProfile();
        if ($profile === null) {
            return;
        }

        $input = $this->inputFrom($_POST);

        if (!Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
            $this->render($profile, $input, ['_form' => 'Your session has expired. Please try again.'], []);
            return;
        }

        $errors = $this->validateProfile($input, (int) $profile['user_id']);
        if ($errors !== []) {
            $this->render($profile, $input, $errors, []);
            return;
        }

        $email = Auth::normalizeEmail($input['email']);
        $userId = (int) $profile['user_id'];

        User::updateOwnProfile(
            $userId,
            $input['first_name'],
            $input['last_name'],
            $email,
            $input['program_dept'] === '' ? null : $input['program_dept']
        );

        // The session carries a copy of the identity for the header greeting —
        // refresh it so the change is visible immediately, not next login.
        Auth::refreshIdentity($input['first_name'], $input['last_name'], $email);

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $this->audit($userId, 'profile_update', $email, $ip);

        $this->flash('ok', 'Your profile was updated.');
        $this->redirectToProfile();
    }

    public function changePassword(): void
    {
        Guard::requireAuth();

        $profile = $this->currentProfile();
        if ($profile === null) {
            return;
        }

        $passwords = [
            // Never trimmed: leading/trailing spaces are legitimate password characters.
            'current' => (string) ($_POST['current_password'] ?? ''),
            'new'     => (string) ($_POST['new_password'] ?? ''),
            'confirm' => (string) ($_POST['confirm_password'] ?? ''),
        ];

        if (!Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
            $this->render($profile, $this->inputFromProfile($profile), [], ['_form' => 'Your session has expired. Please try again.']);
            return;
        }

        $errors = $this->validatePassword($passwords, $profile['password_hash']);
        if ($errors !== []) {
            $this->render($profile, $this->inputFromProfile($profile), [], $errors);
            return;
        }

        $userId = (int) $profile['user_id'];
        User::updatePasswordHash($userId, password_hash($passwords['new'], PASSWORD_BCRYPT));

        // Rotate the session id after a credential change (session-fixation
        // hygiene). Full "log out my other devices" would need a server-side
        // session store to enumerate; rotating the current id is the standard
        // minimum for a single-node deployment.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $this->audit($userId, 'password_change', $profile['email'], $ip);

        $this->flash('ok', 'Your password was changed.');
        $this->redirectToProfile();
    }

    /**
     * The signed-in user's DB row. Renders 404 and returns null if the row has
     * vanished mid-session (deleted out from under an open session).
     *
     * @return array{user_id:int,employee_no:?string,first_name:string,last_name:string,email:string,program_dept:?string,password_hash:string,role_name:string}|null
     */
    private function currentProfile(): ?array
    {
        $profile = User::profileFor((int) (Auth::user()['user_id'] ?? 0));

        if ($profile === null) {
            http_response_code(404);
            $this->view('errors/404', ['appName' => $this->config()['app']['name']]);
        }

        return $profile;
    }

    /**
     * @param array{user_id:int,employee_no:?string,first_name:string,last_name:string,email:string,program_dept:?string,password_hash:string,role_name:string} $profile
     * @return array{first_name:string,last_name:string,email:string,program_dept:string}
     */
    private function inputFromProfile(array $profile): array
    {
        return [
            'first_name'   => $profile['first_name'],
            'last_name'    => $profile['last_name'],
            'email'        => $profile['email'],
            'program_dept' => (string) ($profile['program_dept'] ?? ''),
        ];
    }

    /**
     * @return array{first_name:string,last_name:string,email:string,program_dept:string}
     */
    private function inputFrom(array $post): array
    {
        return [
            'first_name'   => trim((string) ($post['first_name'] ?? '')),
            'last_name'    => trim((string) ($post['last_name'] ?? '')),
            'email'        => trim((string) ($post['email'] ?? '')),
            'program_dept' => trim((string) ($post['program_dept'] ?? '')),
        ];
    }

    /**
     * @param array{first_name:string,last_name:string,email:string,program_dept:string} $input
     * @return array<string,string>
     */
    private function validateProfile(array $input, int $userId): array
    {
        $errors = [];

        // Caps match the column widths (VARCHAR(60)/(120)/(60)) so an over-long
        // value is rejected here rather than silently truncated on write.
        if ($input['first_name'] === '') {
            $errors['first_name'] = 'First name is required.';
        } elseif (mb_strlen($input['first_name']) > 60) {
            $errors['first_name'] = 'First name must be 60 characters or fewer.';
        }

        if ($input['last_name'] === '') {
            $errors['last_name'] = 'Last name is required.';
        } elseif (mb_strlen($input['last_name']) > 60) {
            $errors['last_name'] = 'Last name must be 60 characters or fewer.';
        }

        if ($input['email'] === '') {
            $errors['email'] = 'Email is required.';
        } elseif (mb_strlen($input['email']) > 120) {
            $errors['email'] = 'Email must be 120 characters or fewer.';
        } elseif (filter_var($input['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Enter a valid email address.';
        } elseif (User::existsByEmail(Auth::normalizeEmail($input['email']), $userId)) {
            $errors['email'] = 'Another account already uses this email address.';
        }

        if (mb_strlen($input['program_dept']) > 80) {
            $errors['program_dept'] = 'Program / department must be 80 characters or fewer.';
        }

        return $errors;
    }

    /**
     * @param array{current:string,new:string,confirm:string} $passwords
     * @return array<string,string>
     */
    private function validatePassword(array $passwords, string $currentHash): array
    {
        $errors = [];

        if ($passwords['current'] === '') {
            $errors['current_password'] = 'Enter your current password.';
        } elseif (!password_verify($passwords['current'], $currentHash)) {
            $errors['current_password'] = 'That is not your current password.';
        }

        // Bounds are in BYTES, not characters: bcrypt silently truncates input
        // past 72 bytes, so a longer password would have unused tail characters.
        $newLength = strlen($passwords['new']);
        if ($passwords['new'] === '') {
            $errors['new_password'] = 'Enter a new password.';
        } elseif ($newLength < 8 || $newLength > 72) {
            $errors['new_password'] = 'Password must be between 8 and 72 characters.';
        } elseif (trim($passwords['new']) === '') {
            $errors['new_password'] = 'Password cannot be only spaces.';
        } elseif (strpos($passwords['new'], "\0") !== false) {
            // bcrypt (password_hash with PASSWORD_BCRYPT) throws a ValueError on
            // a NUL byte; reject it here so a crafted password is a validation
            // message, not an uncaught 500.
            $errors['new_password'] = 'Password cannot contain null characters.';
        } elseif ($passwords['new'] === $passwords['current']) {
            $errors['new_password'] = 'The new password must be different from your current one.';
        }

        if (!isset($errors['new_password']) && $passwords['confirm'] !== $passwords['new']) {
            $errors['confirm_password'] = 'The two passwords do not match.';
        }

        return $errors;
    }

    /**
     * @param array{user_id:int,employee_no:?string,first_name:string,last_name:string,email:string,program_dept:?string,password_hash:string,role_name:string} $profile
     * @param array{first_name:string,last_name:string,email:string,program_dept:string} $input
     * @param array<string,string> $profileErrors
     * @param array<string,string> $passwordErrors
     * @param array{type:string,message:string}|null $flash
     */
    private function render(array $profile, array $input, array $profileErrors, array $passwordErrors, ?array $flash = null): void
    {
        $this->view('profile/index', [
            'appName'        => $this->config()['app']['name'],
            'profile'        => $profile,
            'input'          => $input,
            'profileErrors'  => $profileErrors,
            'passwordErrors' => $passwordErrors,
            'flash'          => $flash,
            'csrf'           => Csrf::token(),
        ]);
    }

    /**
     * Best-effort audit write on `user`: an audit-log failure must never turn a
     * profile or password change that already committed into a 500.
     */
    private function audit(int $userId, string $action, string $detail, string $ip): void
    {
        try {
            AuditLog::record($userId, $action, 'user', $userId, $detail, $ip);
        } catch (\Throwable $e) {
            error_log('[CCIS-DMS] audit write failed (' . $action . ' user ' . $userId . '): ' . $e->getMessage());
        }
    }

    private function flash(string $type, string $message): void
    {
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    }

    private function redirectToProfile(): void
    {
        header('Location: ' . url('/profile'));
        exit;
    }
}
