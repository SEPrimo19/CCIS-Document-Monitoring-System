<?php
/**
 * New-requirement form (FR-28) with audience targeting (FR-35). Publishing is
 * eager — Pending submissions are created for the chosen audience immediately —
 * and always targets the active academic period, so there is no period picker.
 *
 * Progressive enhancement, not a JS dependency: all three audience controls are
 * rendered and submitted unconditionally, and the server decides which payload
 * to read from the radio. app.js only collapses the two that do not apply, and
 * only once `.js` is on <html>. With scripting off the page is longer but
 * completely usable, and nothing hidden here is a security control — the real
 * check is RequirementController::validateAudience().
 *
 * @var string $appName
 * @var list<array{doc_type_id:int,name:string,description:?string,is_active:int,created_by:?int,created_at:string}> $docTypes
 * @var array{period_id:int,school_year:string,semester:string,label:?string,start_date:?string,end_date:?string,is_active:int}|null $period
 * @var list<array{program_id:int,code:string,name:string}> $programs
 * @var list<array{user_id:int,first_name:string,last_name:string,program_code:?string}> $faculty
 * @var array{doc_type_id:string,title:string,description:string,deadline:string,applies_to:string,target_program_id:string,target_faculty:list<string>} $input
 * @var array<string,string> $errors
 * @var string $csrf
 */
require __DIR__ . '/../../partials/header.php';

$disabled = $period === null || $docTypes === [];
$appliesTo = in_array($input['applies_to'], ['all_faculty', 'program', 'individual'], true)
    ? $input['applies_to']
    : 'all_faculty';
$selectedFaculty = $input['target_faculty'];
?>
<section class="form-card">
    <span class="badge">Secretary</span>
    <h1>New requirement</h1>

    <?php if (!empty($errors['_csrf'])): ?>
        <p class="alert alert-err" role="alert"><?= htmlspecialchars($errors['_csrf']) ?></p>
    <?php endif; ?>

    <?php if ($disabled): ?>
        <p class="alert alert-err" role="alert">
            <?php if ($period === null && $docTypes === []): ?>
                Add an active document type and activate an academic period before publishing a requirement.
            <?php elseif ($period === null): ?>
                No active academic period is set. Activate a period before publishing a requirement.
            <?php else: ?>
                No active document types exist. Add one before publishing a requirement.
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <form method="post" action="<?= url('/admin/requirements') ?>" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

        <div class="field">
            <label for="doc_type_id">Document type</label>
            <select id="doc_type_id" name="doc_type_id" <?= $disabled ? 'disabled' : 'required' ?>>
                <option value="">— Select —</option>
                <?php foreach ($docTypes as $type): ?>
                    <option value="<?= (int) $type['doc_type_id'] ?>" <?= (string) $type['doc_type_id'] === $input['doc_type_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($type['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (!empty($errors['doc_type_id'])): ?>
                <p class="field-err"><?= htmlspecialchars($errors['doc_type_id']) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="title">Title</label>
            <input
                type="text"
                id="title"
                name="title"
                value="<?= htmlspecialchars($input['title']) ?>"
                maxlength="120"
                <?= $disabled ? 'disabled' : 'required' ?>
                autofocus
            >
            <?php if (!empty($errors['title'])): ?>
                <p class="field-err"><?= htmlspecialchars($errors['title']) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="description">Description</label>
            <textarea id="description" name="description" rows="3" maxlength="255" <?= $disabled ? 'disabled' : '' ?>><?= htmlspecialchars($input['description']) ?></textarea>
            <?php if (!empty($errors['description'])): ?>
                <p class="field-err"><?= htmlspecialchars($errors['description']) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="deadline">Deadline</label>
            <input
                type="date"
                id="deadline"
                name="deadline"
                value="<?= htmlspecialchars($input['deadline']) ?>"
                <?= $disabled ? 'disabled' : 'required' ?>
            >
            <?php if (!empty($errors['deadline'])): ?>
                <p class="field-err"><?= htmlspecialchars($errors['deadline']) ?></p>
            <?php endif; ?>
        </div>

        <?php // <fieldset>/<legend> rather than a bare <label>: this is a radio ?>
        <?php // group, so screen readers need the grouping to announce "Applies ?>
        <?php // to" before each option. ?>
        <fieldset class="field audience-field" data-audience>
            <legend>Applies to</legend>

            <label class="radio-label">
                <input type="radio" name="applies_to" value="all_faculty" data-audience-radio
                       <?= $appliesTo === 'all_faculty' ? 'checked' : '' ?> <?= $disabled ? 'disabled' : '' ?>>
                All faculty
            </label>
            <p class="field-help">Every active Faculty account gets a Pending submission as soon as this is published.</p>

            <label class="radio-label">
                <input type="radio" name="applies_to" value="program" data-audience-radio
                       <?= $appliesTo === 'program' ? 'checked' : '' ?> <?= $disabled ? 'disabled' : '' ?>>
                By program
            </label>
            <div class="audience-panel" data-audience-panel="program">
                <label for="target_program_id">Program</label>
                <select id="target_program_id" name="target_program_id" <?= $disabled ? 'disabled' : '' ?>>
                    <option value="">— Select —</option>
                    <?php foreach ($programs as $program): ?>
                        <option value="<?= (int) $program['program_id'] ?>" <?= (string) $program['program_id'] === $input['target_program_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($program['code'] . ' — ' . $program['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($programs === []): ?>
                    <p class="field-help">No active programs are defined.</p>
                <?php endif; ?>
                <?php if (!empty($errors['target_program_id'])): ?>
                    <p class="field-err"><?= htmlspecialchars($errors['target_program_id']) ?></p>
                <?php endif; ?>
            </div>

            <label class="radio-label">
                <input type="radio" name="applies_to" value="individual" data-audience-radio
                       <?= $appliesTo === 'individual' ? 'checked' : '' ?> <?= $disabled ? 'disabled' : '' ?>>
                Specific faculty
            </label>
            <div class="audience-panel" data-audience-panel="individual">
                <span class="field-label">Faculty</span>
                <?php if ($faculty === []): ?>
                    <p class="field-help">No active Faculty accounts exist yet.</p>
                <?php else: ?>
                    <ul class="checkbox-list">
                        <?php foreach ($faculty as $member): ?>
                            <li>
                                <label class="checkbox-label">
                                    <input type="checkbox" name="target_faculty[]"
                                           value="<?= (int) $member['user_id'] ?>"
                                           <?= in_array((string) $member['user_id'], $selectedFaculty, true) ? 'checked' : '' ?>
                                           <?= $disabled ? 'disabled' : '' ?>>
                                    <?= htmlspecialchars($member['last_name'] . ', ' . $member['first_name']) ?>
                                    <?php if ($member['program_code'] !== null): ?>
                                        <span class="muted-note"><?= htmlspecialchars($member['program_code']) ?></span>
                                    <?php endif; ?>
                                </label>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if (!empty($errors['target_faculty'])): ?>
                    <p class="field-err"><?= htmlspecialchars($errors['target_faculty']) ?></p>
                <?php endif; ?>
            </div>

            <?php if (!empty($errors['applies_to'])): ?>
                <p class="field-err"><?= htmlspecialchars($errors['applies_to']) ?></p>
            <?php endif; ?>
        </fieldset>

        <div class="form-actions">
            <button type="submit" class="btn-primary btn-inline" <?= $disabled ? 'disabled' : '' ?>>Publish requirement</button>
            <a href="<?= url('/admin/requirements') ?>" class="btn-sm btn-secondary">Cancel</a>
        </div>
    </form>
</section>
<?php require __DIR__ . '/../../partials/footer.php'; ?>
