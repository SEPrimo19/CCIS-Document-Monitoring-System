<?php
/**
 * New-requirement form. Publishing is eager (Pending submissions are created
 * for every active faculty immediately) and always targets the active
 * academic period, so there is no period picker here.
 *
 * @var string $appName
 * @var list<array{doc_type_id:int,name:string,description:?string,is_active:int,created_by:?int,created_at:string}> $docTypes
 * @var array{period_id:int,school_year:string,semester:string,label:?string,start_date:?string,end_date:?string,is_active:int}|null $period
 * @var array{doc_type_id:string,title:string,description:string,deadline:string} $input
 * @var array<string,string> $errors
 * @var string $csrf
 */
require __DIR__ . '/../../partials/header.php';

$disabled = $period === null || $docTypes === [];
?>
<section class="form-card">
    <span class="badge">Administrator</span>
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

        <div class="field">
            <label>Applies to</label>
            <p class="field-help">All faculty — every active Faculty account gets a Pending submission for this requirement as soon as it is published.</p>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn-primary btn-inline" <?= $disabled ? 'disabled' : '' ?>>Publish requirement</button>
            <a href="<?= url('/admin/requirements') ?>" class="btn-sm btn-secondary">Cancel</a>
        </div>
    </form>
</section>
<?php require __DIR__ . '/../../partials/footer.php'; ?>
