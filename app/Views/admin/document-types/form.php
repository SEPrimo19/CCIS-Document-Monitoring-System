<?php
/**
 * Shared create/edit form for a document type. Editing when $docType is set.
 *
 * @var string $appName
 * @var array{doc_type_id:int,name:string,description:?string,is_active:int,created_by:?int,created_at:string}|null $docType
 * @var string $name
 * @var string $description
 * @var array<string,string> $errors
 * @var string $csrf
 */
require __DIR__ . '/../../partials/header.php';

$isEdit = $docType !== null;
$action = $isEdit ? url('/admin/document-types/' . $docType['doc_type_id']) : url('/admin/document-types');
?>
<section class="form-card">
    <span class="badge">Secretary</span>
    <h1><?= $isEdit ? 'Edit document type' : 'New document type' ?></h1>

    <?php if (!empty($errors['_csrf'])): ?>
        <p class="alert alert-err" role="alert"><?= htmlspecialchars($errors['_csrf']) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= $action ?>" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

        <div class="field">
            <label for="name">Name</label>
            <input
                type="text"
                id="name"
                name="name"
                value="<?= htmlspecialchars($name) ?>"
                maxlength="80"
                required
                autofocus
            >
            <?php if (!empty($errors['name'])): ?>
                <p class="field-err"><?= htmlspecialchars($errors['name']) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="description">Description</label>
            <textarea id="description" name="description" rows="3" maxlength="255"><?= htmlspecialchars($description) ?></textarea>
            <?php if (!empty($errors['description'])): ?>
                <p class="field-err"><?= htmlspecialchars($errors['description']) ?></p>
            <?php endif; ?>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn-primary btn-inline"><?= $isEdit ? 'Save changes' : 'Create document type' ?></button>
            <a href="<?= url('/admin/document-types') ?>" class="btn-sm btn-secondary">Cancel</a>
        </div>
    </form>
</section>
<?php require __DIR__ . '/../../partials/footer.php'; ?>
