<?php
/**
 * Shared create/edit form for a user account. Editing when $target is set.
 * Password is required on create; blank on edit keeps the current hash.
 *
 * @var string $appName
 * @var array{user_id:int,first_name:string,last_name:string,email:string,role_id:int,role_name:string,program_id:?int,status:string}|null $target
 * @var list<array{role_id:int,role_name:string}> $roles
 * @var list<array{program_id:int,code:string,name:string}> $programs
 * @var array{first_name:string,last_name:string,email:string,role_id:string,program_id:string,password:string} $input
 * @var array<string,string> $errors
 * @var string $csrf
 */
require __DIR__ . '/../../partials/header.php';

$isEdit = $target !== null;
$action = $isEdit ? url('/admin/users/' . $target['user_id']) : url('/admin/users');
?>
<section class="form-card">
    <span class="badge">Secretary</span>
    <h1><?= $isEdit ? 'Edit user' : 'New user' ?></h1>

    <?php // Form-level error (expired session, or the DB refusing the write) — ?>
    <?php // as opposed to the per-field errors rendered next to each input. ?>
    <?php if (!empty($errors['_form'])): ?>
        <p class="alert alert-err" role="alert"><?= htmlspecialchars($errors['_form']) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= $action ?>" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

        <div class="field">
            <label for="first_name">First name</label>
            <input
                type="text"
                id="first_name"
                name="first_name"
                value="<?= htmlspecialchars($input['first_name']) ?>"
                maxlength="60"
                required
                autofocus
            >
            <?php if (!empty($errors['first_name'])): ?>
                <p class="field-err"><?= htmlspecialchars($errors['first_name']) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="last_name">Last name</label>
            <input
                type="text"
                id="last_name"
                name="last_name"
                value="<?= htmlspecialchars($input['last_name']) ?>"
                maxlength="60"
                required
            >
            <?php if (!empty($errors['last_name'])): ?>
                <p class="field-err"><?= htmlspecialchars($errors['last_name']) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="email">Email</label>
            <input
                type="email"
                id="email"
                name="email"
                value="<?= htmlspecialchars($input['email']) ?>"
                maxlength="120"
                required
            >
            <?php if (!empty($errors['email'])): ?>
                <p class="field-err"><?= htmlspecialchars($errors['email']) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="role_id">Role</label>
            <select id="role_id" name="role_id" required>
                <option value="">— Select —</option>
                <?php foreach ($roles as $role): ?>
                    <option value="<?= (int) $role['role_id'] ?>" <?= (string) $role['role_id'] === $input['role_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($role['role_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (!empty($errors['role_id'])): ?>
                <p class="field-err"><?= htmlspecialchars($errors['role_id']) ?></p>
            <?php endif; ?>
        </div>

        <?php // Program is set HERE, by the Secretary, and is deliberately absent ?>
        <?php // from /profile: requirement audiences can target a program (FR-35), ?>
        <?php // so a self-editable program field would let a faculty member move ?>
        <?php // themselves out of a requirement aimed at theirs. ?>
        <div class="field">
            <label for="program_id">Program</label>
            <select id="program_id" name="program_id">
                <option value="">— None —</option>
                <?php foreach ($programs as $program): ?>
                    <option value="<?= (int) $program['program_id'] ?>" <?= (string) $program['program_id'] === $input['program_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($program['code'] . ' — ' . $program['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field-help">
                Determines which program-targeted requirements this account receives (FR-35, FR-36).
                Leave as &ldquo;None&rdquo; for Secretary accounts.
            </p>
            <?php if (!empty($errors['program_id'])): ?>
                <p class="field-err"><?= htmlspecialchars($errors['program_id']) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="password">Password</label>
            <input
                type="password"
                id="password"
                name="password"
                maxlength="72"
                autocomplete="new-password"
                <?= $isEdit ? '' : 'required' ?>
            >
            <p class="field-help">
                <?= $isEdit ? 'Leave blank to keep the current password.' : 'Minimum 8 characters.' ?>
            </p>
            <?php if (!empty($errors['password'])): ?>
                <p class="field-err"><?= htmlspecialchars($errors['password']) ?></p>
            <?php endif; ?>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn-primary btn-inline"><?= $isEdit ? 'Save changes' : 'Create user' ?></button>
            <a href="<?= url('/admin/users') ?>" class="btn-sm btn-secondary">Cancel</a>
        </div>
    </form>
</section>
<?php require __DIR__ . '/../../partials/footer.php'; ?>
