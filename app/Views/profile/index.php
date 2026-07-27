<?php
/**
 * Self-service profile + change password (FR-5). Two independent forms on one
 * page, each with its own error bag, so a failed password change never wipes
 * the profile fields the user had just edited (and vice versa).
 *
 * @var string $appName
 * @var array{user_id:int,employee_no:?string,first_name:string,last_name:string,email:string,program_dept:?string,password_hash:string,role_name:string} $profile
 * @var array{first_name:string,last_name:string,email:string,program_dept:string} $input
 * @var array<string,string> $profileErrors
 * @var array<string,string> $passwordErrors
 * @var array{type:string,message:string}|null $flash
 * @var string $csrf
 */
require __DIR__ . '/../partials/header.php';
?>
<section class="page-head">
    <div>
        <span class="badge">My Account</span>
        <h1>My Profile</h1>
        <p class="sub">Update your details and change your password (FR-5).</p>
    </div>
</section>

<?php if ($flash !== null): ?>
    <p class="alert <?= $flash['type'] === 'ok' ? 'alert-ok' : 'alert-err' ?>" role="alert">
        <?= htmlspecialchars($flash['message']) ?>
    </p>
<?php endif; ?>

<section class="form-card">
    <h2>Details</h2>

    <?php if (!empty($profileErrors['_form'])): ?>
        <p class="alert alert-err" role="alert"><?= htmlspecialchars($profileErrors['_form']) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= url('/profile') ?>" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

        <?php // Read-only rows use .field-label (a plain <span>), not <label>: a ?>
        <?php // <label> with no associated control is meaningless to a screen reader. ?>
        <div class="field">
            <span class="field-label">Role</span>
            <p class="field-help">
                <span class="role-pill"><?= htmlspecialchars($profile['role_name']) ?></span>
                Your role is set by an administrator and cannot be changed here.
            </p>
        </div>

        <?php if ($profile['employee_no'] !== null && $profile['employee_no'] !== ''): ?>
            <div class="field">
                <span class="field-label">Employee number</span>
                <p class="field-help"><?= htmlspecialchars($profile['employee_no']) ?></p>
            </div>
        <?php endif; ?>

        <div class="field">
            <label for="first_name">First name</label>
            <input type="text" id="first_name" name="first_name" value="<?= htmlspecialchars($input['first_name']) ?>" maxlength="60" required>
            <?php if (!empty($profileErrors['first_name'])): ?>
                <p class="field-err"><?= htmlspecialchars($profileErrors['first_name']) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="last_name">Last name</label>
            <input type="text" id="last_name" name="last_name" value="<?= htmlspecialchars($input['last_name']) ?>" maxlength="60" required>
            <?php if (!empty($profileErrors['last_name'])): ?>
                <p class="field-err"><?= htmlspecialchars($profileErrors['last_name']) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="<?= htmlspecialchars($input['email']) ?>" maxlength="120" required>
            <p class="field-help">This is also the address you sign in with.</p>
            <?php if (!empty($profileErrors['email'])): ?>
                <p class="field-err"><?= htmlspecialchars($profileErrors['email']) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="program_dept">Program / department</label>
            <input type="text" id="program_dept" name="program_dept" value="<?= htmlspecialchars($input['program_dept']) ?>" maxlength="80" placeholder="BSIT">
            <?php if (!empty($profileErrors['program_dept'])): ?>
                <p class="field-err"><?= htmlspecialchars($profileErrors['program_dept']) ?></p>
            <?php endif; ?>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn-primary btn-inline">Save details</button>
        </div>
    </form>
</section>

<section class="form-card">
    <h2>Change password</h2>

    <?php if (!empty($passwordErrors['_form'])): ?>
        <p class="alert alert-err" role="alert"><?= htmlspecialchars($passwordErrors['_form']) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= url('/profile/password') ?>" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

        <div class="field">
            <label for="current_password">Current password</label>
            <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
            <?php if (!empty($passwordErrors['current_password'])): ?>
                <p class="field-err"><?= htmlspecialchars($passwordErrors['current_password']) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="new_password">New password</label>
            <input type="password" id="new_password" name="new_password" autocomplete="new-password" required>
            <p class="field-help">At least 8 characters.</p>
            <?php if (!empty($passwordErrors['new_password'])): ?>
                <p class="field-err"><?= htmlspecialchars($passwordErrors['new_password']) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="confirm_password">Confirm new password</label>
            <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required>
            <?php if (!empty($passwordErrors['confirm_password'])): ?>
                <p class="field-err"><?= htmlspecialchars($passwordErrors['confirm_password']) ?></p>
            <?php endif; ?>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn-primary btn-inline">Change password</button>
        </div>
    </form>
</section>
<?php require __DIR__ . '/../partials/footer.php'; ?>
