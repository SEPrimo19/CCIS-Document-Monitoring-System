<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Guard;
use App\Models\AuditLog;
use App\Models\User;
use finfo;

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
 *  2. **Changing a sign-in credential must prove knowledge of the current
 *     password.** An unattended signed-in browser must not be enough to lock
 *     the real owner out of their account, so the current password is verified
 *     with password_verify() before a new hash is written — and, for the same
 *     reason, before the email address is changed. Email is the login
 *     identifier and there is no self-service reset and no mail delivery, so
 *     changing it is a *harder* lockout than changing the password. Name
 *     carries no such risk and stays editable without one.
 *
 * What used to be here and no longer is: **program / department**. It was a
 * free-text field the user could type anything into. Once requirement
 * audiences could target a program (FR-35), a self-editable program became an
 * obligation-evasion path — a faculty member could edit their way out of a
 * requirement aimed at their program. It is now reference data (FR-36)
 * assigned by the Secretary on the user form, shown read-only below. The POST
 * body is not merely ignored: User::updateOwnProfile()'s statement does not
 * name the column at all, so a forged program_id has nothing to bind to.
 * This also closes LOW-5 in SECURITY-FINDINGS-2026-07-24.md (the
 * program_dept length-cap mismatch) by removing the field it applied to.
 */
final class ProfileController extends Controller
{
    /**
     * Profile-photo limits (FR-40).
     *
     * This build has NO GD extension, so the server cannot decode, re-encode or
     * downscale an upload — the usual way a hostile image is neutralised. Caps
     * therefore do that work instead, and they are the reason a 10000x10000 PNG
     * cannot end up being shipped into the sidebar of every page.
     *
     * 2 MB is generous for a headshot and far below the 10 MB document limit;
     * a photo is decoration, not evidence, so it does not get a document's
     * allowance. The pixel bounds are checked with getimagesize(), which parses
     * headers only (no GD, no full decode) and so costs almost nothing.
     */
    private const MAX_AVATAR_BYTES = 2 * 1024 * 1024;
    private const MAX_AVATAR_PIXELS = 4000;
    private const MIN_AVATAR_PIXELS = 32;

    /**
     * Extension => [allowed finfo MIME types, expected IMAGETYPE_* constant].
     *
     * finfo is only a pre-filter; getimagesize()'s detected type is what
     * actually proves the bytes are the image they claim to be, and the two
     * must agree with the extension before anything is written to disk.
     *
     * SVG IS DELIBERATELY ABSENT AND MUST NEVER BE ADDED. An SVG is not a
     * raster image, it is a document that can carry <script>; serving a
     * user-supplied one back from our own origin is stored XSS. "It is just
     * another image format" is exactly how that gets introduced later.
     */
    private const AVATAR_TYPES = [
        'jpg'  => [['image/jpeg'], IMAGETYPE_JPEG],
        'jpeg' => [['image/jpeg'], IMAGETYPE_JPEG],
        'png'  => [['image/png'], IMAGETYPE_PNG],
        'webp' => [['image/webp'], IMAGETYPE_WEBP],
    ];

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

        $email = Auth::normalizeEmail($input['email']);

        // Re-authenticate an email change (see rule 2 in the class docblock).
        // Only when the address actually differs: demanding a password to fix
        // a typo in a surname would be friction with nothing behind it. Skipped
        // when the address itself failed validation — there is no change to
        // gate yet, and the user should see both problems at once rather than
        // one after the other.
        $emailChanged = !isset($errors['email']) && $email !== Auth::normalizeEmail($profile['email']);

        if ($emailChanged) {
            // Never trimmed: leading/trailing spaces are legitimate password
            // characters (same rule as changePassword()).
            $currentPassword = (string) ($_POST['current_password'] ?? '');

            if ($currentPassword === '') {
                $errors['current_password'] = 'Enter your current password to change your email address.';
            } elseif (!password_verify($currentPassword, $profile['password_hash'])) {
                $errors['current_password'] = 'That is not your current password.';
            }
        }

        if ($errors !== []) {
            $this->render($profile, $input, $errors, []);
            return;
        }

        $userId = (int) $profile['user_id'];

        User::updateOwnProfile($userId, $input['first_name'], $input['last_name'], $email);

        // The session carries a copy of the identity for the header greeting —
        // refresh it so the change is visible immediately, not next login.
        Auth::refreshIdentity($input['first_name'], $input['last_name'], $email);

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        // On an email change record BOTH addresses: the new one alone would
        // leave no trail back to the account as it was, which is exactly what
        // an investigator needs after a disputed change. Capped to the
        // audit_log.details column width (VARCHAR(255)).
        $detail = $emailChanged
            ? mb_substr(sprintf('email %s -> %s', $profile['email'], $email), 0, 255)
            : $email;
        $this->audit($userId, 'profile_update', $detail, $ip);

        $this->flash(
            'ok',
            $emailChanged
                ? 'Your profile was updated. Sign in with your new email address from now on.'
                : 'Your profile was updated.'
        );
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
     * @return array{user_id:int,employee_no:?string,first_name:string,last_name:string,email:string,program_code:?string,program_name:?string,password_hash:string,role_name:string}|null
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
     * @param array{user_id:int,employee_no:?string,first_name:string,last_name:string,email:string,program_code:?string,program_name:?string,password_hash:string,role_name:string} $profile
     * @return array{first_name:string,last_name:string,email:string}
     */
    private function inputFromProfile(array $profile): array
    {
        return [
            'first_name' => $profile['first_name'],
            'last_name'  => $profile['last_name'],
            'email'      => $profile['email'],
        ];
    }

    /**
     * The three fields a user may edit about themselves. Anything else in the
     * POST body — role_id, status, program_id — is simply never read.
     *
     * @return array{first_name:string,last_name:string,email:string}
     */
    private function inputFrom(array $post): array
    {
        return [
            'first_name' => trim((string) ($post['first_name'] ?? '')),
            'last_name'  => trim((string) ($post['last_name'] ?? '')),
            'email'      => trim((string) ($post['email'] ?? '')),
        ];
    }

    /**
     * @param array{first_name:string,last_name:string,email:string} $input
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
     * @param array{user_id:int,employee_no:?string,first_name:string,last_name:string,email:string,program_code:?string,program_name:?string,password_hash:string,role_name:string} $profile
     * @param array{first_name:string,last_name:string,email:string} $input
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

    /**
     * Upload or replace the signed-in user's profile photo (FR-40).
     *
     * A photo is the one thing on this screen a user may change about
     * themselves without re-authenticating, and unlike role, status or program
     * it decides nothing about what the system expects of them (contrast
     * FR-36), so it carries none of the obligation-evasion risk that moved
     * program to the Secretary.
     *
     * The user id comes from the session. There is no user_id in this form and
     * none is read, so a forged one has nothing to bind to.
     */
    public function uploadPhoto(): void
    {
        Guard::requireAuth();

        $user = Auth::user();
        $userId = (int) ($user['user_id'] ?? 0);

        if (!Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
            $this->flash('err', 'Your session has expired. Please try again.');
            $this->redirectToProfile();
            return;
        }

        $file = $_FILES['photo'] ?? null;
        $error = $this->validateAvatar(is_array($file) ? $file : []);

        if ($error !== null) {
            $this->flash('err', $error);
            $this->redirectToProfile();
            return;
        }

        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $directory = AvatarController::directory();

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            $this->flash('err', 'The photo could not be saved. Please try again.');
            $this->redirectToProfile();
            return;
        }

        // The stored name is generated here; the client's filename never
        // reaches the filesystem, so it cannot traverse a path or collide.
        $storedName = sprintf('user%d_%s.%s', $userId, bin2hex(random_bytes(8)), $ext);
        $destination = $directory . DIRECTORY_SEPARATOR . $storedName;

        if (!move_uploaded_file((string) $file['tmp_name'], $destination)) {
            $this->flash('err', 'The photo could not be saved. Please try again.');
            $this->redirectToProfile();
            return;
        }

        // Point the row at the new file first, then delete the one it used to
        // reference. In that order a crash between the two leaves an orphan on
        // disk; the reverse order would leave a row pointing at nothing, which
        // is a broken avatar on every page. Deleting on replace is deliberate:
        // nothing else in this application unlinks, which is how
        // storage/uploads/ reached 187 MB against a single row.
        $previous = User::updateAvatarPath($userId, $storedName);
        $this->deleteAvatarFile($previous);

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $this->audit($userId, 'profile_photo_set', 'Profile photo uploaded.', $ip);
        $this->flash('ok', 'Your profile photo was updated.');
        $this->redirectToProfile();
    }

    /**
     * Remove the signed-in user's profile photo (FR-40); the avatar falls back
     * to their initials. The file is unlinked, not merely unreferenced.
     */
    public function removePhoto(): void
    {
        Guard::requireAuth();

        $user = Auth::user();
        $userId = (int) ($user['user_id'] ?? 0);

        if (!Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
            $this->flash('err', 'Your session has expired. Please try again.');
            $this->redirectToProfile();
            return;
        }

        $previous = User::updateAvatarPath($userId, null);

        if ($previous === null) {
            $this->flash('err', 'There is no profile photo to remove.');
            $this->redirectToProfile();
            return;
        }

        $this->deleteAvatarFile($previous);

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $this->audit($userId, 'profile_photo_removed', 'Profile photo removed.', $ip);
        $this->flash('ok', 'Your profile photo was removed.');
        $this->redirectToProfile();
    }

    /**
     * Validate an uploaded profile photo. Returns an error message, or null
     * when the file is acceptable.
     *
     * Order matters: the cheap checks (upload status, size) run before the ones
     * that read the file, and nothing is written anywhere until all of them
     * pass.
     */
    private function validateAvatar(array $file): ?string
    {
        $code = $file['error'] ?? UPLOAD_ERR_NO_FILE;

        if ($code === UPLOAD_ERR_NO_FILE || ($file['name'] ?? '') === '') {
            return 'Choose an image file to upload.';
        }

        if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
            return 'That image is larger than the 2 MB limit.';
        }

        if ($code !== UPLOAD_ERR_OK) {
            return 'The image could not be uploaded. Please try again.';
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');

        // Proves the path really is this request's upload and not an arbitrary
        // server path smuggled in through the form.
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            return 'The image could not be uploaded. Please try again.';
        }

        $size = (int) ($file['size'] ?? 0);

        if ($size <= 0) {
            return 'That file is empty.';
        }

        if ($size > self::MAX_AVATAR_BYTES) {
            return 'That image is larger than the 2 MB limit.';
        }

        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));

        if (!isset(self::AVATAR_TYPES[$ext])) {
            return 'Profile photos must be a JPG, PNG or WEBP image.';
        }

        [$allowedMimes, $expectedType] = self::AVATAR_TYPES[$ext];

        $detected = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmpName);

        if (!in_array($detected, $allowedMimes, true)) {
            return 'That file is not a valid ' . strtoupper($ext) . ' image.';
        }

        // The structural check: getimagesize() parses the real header, so a
        // renamed text file or PHP script fails here even if finfo was fooled.
        $info = @getimagesize($tmpName);

        if (!is_array($info) || !isset($info[0], $info[1], $info[2])) {
            return 'That file is not a valid image.';
        }

        if ((int) $info[2] !== $expectedType) {
            return 'That image is not really a ' . strtoupper($ext) . ' file. Rename it to its true format and try again.';
        }

        $width = (int) $info[0];
        $height = (int) $info[1];

        if ($width < self::MIN_AVATAR_PIXELS || $height < self::MIN_AVATAR_PIXELS) {
            return sprintf('That image is too small — it must be at least %dx%d pixels.', self::MIN_AVATAR_PIXELS, self::MIN_AVATAR_PIXELS);
        }

        // Without GD there is no downscaling, so an oversized image would be
        // served at full size into every page. Refuse it instead.
        if ($width > self::MAX_AVATAR_PIXELS || $height > self::MAX_AVATAR_PIXELS) {
            return sprintf('That image is too large — it must be %dx%d pixels or smaller.', self::MAX_AVATAR_PIXELS, self::MAX_AVATAR_PIXELS);
        }

        return null;
    }

    /**
     * Unlink a stored avatar file, defensively.
     *
     * basename() is belt-and-braces: stored names are generated by this class
     * and cannot contain a separator, but this function deletes things, so it
     * refuses to act on anything that is not a plain filename inside the
     * avatar directory.
     */
    private function deleteAvatarFile(?string $storedName): void
    {
        if ($storedName === null || $storedName === '') {
            return;
        }

        if (basename($storedName) !== $storedName) {
            error_log('[CCIS-DMS] refused to delete suspicious avatar name: ' . $storedName);
            return;
        }

        $path = AvatarController::directory() . DIRECTORY_SEPARATOR . $storedName;

        if (is_file($path)) {
            @unlink($path);
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
