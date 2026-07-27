<?php
/**
 * Create / edit an academic period (FR-29). is_active is deliberately absent
 * from this form: activation is a separate, explicit action from the list, so
 * an administrator cannot silently switch what every faculty member sees while
 * correcting a typo in a label.
 *
 * @var string $appName
 * @var array{period_id:int,school_year:string,semester:string,label:?string,start_date:?string,end_date:?string,is_active:int}|null $period
 * @var array{school_year:string,semester:string,label:string,start_date:string,end_date:string} $input
 * @var list<string> $semesters
 * @var array<string,string> $errors
 * @var string $csrf
 */
require __DIR__ . '/../../partials/header.php';

$isEdit = $period !== null;
$action = $isEdit ? url('/admin/periods/' . $period['period_id']) : url('/admin/periods');
?>
<section class="form-card">
    <span class="badge">Secretary</span>
    <h1><?= $isEdit ? 'Edit academic period' : 'New academic period' ?></h1>

    <?php if (!empty($errors['_form'])): ?>
        <p class="alert alert-err" role="alert"><?= htmlspecialchars($errors['_form']) ?></p>
    <?php endif; ?>

    <?php if ($isEdit && (int) $period['is_active'] === 1): ?>
        <p class="alert alert-ok" role="alert">
            This is the active period. Changes here affect what every user currently sees.
        </p>
    <?php endif; ?>

    <form method="post" action="<?= $action ?>" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

        <div class="field">
            <label for="school_year">School year</label>
            <input
                type="text"
                id="school_year"
                name="school_year"
                value="<?= htmlspecialchars($input['school_year']) ?>"
                maxlength="9"
                placeholder="2026-2027"
                required
                autofocus
            >
            <p class="field-help">Format YYYY-YYYY, for example 2026-2027.</p>
            <?php if (!empty($errors['school_year'])): ?>
                <p class="field-err"><?= htmlspecialchars($errors['school_year']) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="semester">Semester</label>
            <select id="semester" name="semester" required>
                <option value="">&mdash; Select &mdash;</option>
                <?php foreach ($semesters as $sem): ?>
                    <option value="<?= htmlspecialchars($sem) ?>" <?= $sem === $input['semester'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($sem) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (!empty($errors['semester'])): ?>
                <p class="field-err"><?= htmlspecialchars($errors['semester']) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="label">Display label</label>
            <input
                type="text"
                id="label"
                name="label"
                value="<?= htmlspecialchars($input['label']) ?>"
                maxlength="60"
                placeholder="AY 2026-2027, 1st Semester"
            >
            <p class="field-help">Optional. Shown on dashboards and reports; defaults to the school year and semester.</p>
            <?php if (!empty($errors['label'])): ?>
                <p class="field-err"><?= htmlspecialchars($errors['label']) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="start_date">Start date</label>
            <input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars($input['start_date']) ?>">
            <p class="field-help">Optional, for reference on reports.</p>
            <?php if (!empty($errors['start_date'])): ?>
                <p class="field-err"><?= htmlspecialchars($errors['start_date']) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="end_date">End date</label>
            <input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars($input['end_date']) ?>">
            <?php if (!empty($errors['end_date'])): ?>
                <p class="field-err"><?= htmlspecialchars($errors['end_date']) ?></p>
            <?php endif; ?>
        </div>

        <?php if (!$isEdit): ?>
            <div class="field">
                <label>Activation</label>
                <p class="field-help">
                    New periods start closed. Activate it from the list when you are ready for faculty
                    to see its requirements.
                </p>
            </div>
        <?php endif; ?>

        <div class="form-actions">
            <button type="submit" class="btn-primary btn-inline"><?= $isEdit ? 'Save changes' : 'Create period' ?></button>
            <a href="<?= url('/admin/periods') ?>" class="btn-sm btn-secondary">Cancel</a>
        </div>
    </form>
</section>
<?php require __DIR__ . '/../../partials/footer.php'; ?>
