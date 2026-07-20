<?php
/**
 * @var string $appName
 * @var list<array{doc_type_id:int,name:string,description:?string,is_active:int,created_by:?int,created_at:string}> $documentTypes
 * @var array{type:string,message:string}|null $flash
 * @var string $csrf
 */
require __DIR__ . '/../../partials/header.php';
?>
<section class="page-head">
    <div>
        <span class="badge">Administrator</span>
        <h1>Document Types</h1>
        <p class="sub">Add, edit, and deactivate the document types faculty submit against (FR-27).</p>
    </div>
    <a href="<?= url('/admin/document-types/new') ?>" class="btn-primary btn-inline">New document type</a>
</section>

<?php if ($flash !== null): ?>
    <p class="alert <?= $flash['type'] === 'ok' ? 'alert-ok' : 'alert-err' ?>" role="alert">
        <?= htmlspecialchars($flash['message']) ?>
    </p>
<?php endif; ?>

<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Description</th>
                <th>Status</th>
                <th>Created</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($documentTypes === []): ?>
                <tr>
                    <td colspan="5" class="table-empty">No document types yet.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($documentTypes as $type): ?>
                <?php $isActive = (int) $type['is_active'] === 1; ?>
                <tr>
                    <td><?= htmlspecialchars($type['name']) ?></td>
                    <td><?= htmlspecialchars($type['description'] ?? '') ?></td>
                    <td>
                        <span class="status-pill <?= $isActive ? 'status-pill-active' : 'status-pill-inactive' ?>">
                            <?= $isActive ? 'Active' : 'Inactive' ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars(date('M j, Y', strtotime($type['created_at']))) ?></td>
                    <td class="table-actions">
                        <a href="<?= url('/admin/document-types/' . $type['doc_type_id'] . '/edit') ?>" class="btn-sm btn-secondary">Edit</a>
                        <?php if ($isActive): ?>
                            <form method="post" action="<?= url('/admin/document-types/' . $type['doc_type_id'] . '/deactivate') ?>" class="inline-form">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                <button type="submit" class="btn-sm btn-danger">Deactivate</button>
                            </form>
                        <?php else: ?>
                            <form method="post" action="<?= url('/admin/document-types/' . $type['doc_type_id'] . '/activate') ?>" class="inline-form">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                <button type="submit" class="btn-sm btn-secondary">Activate</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/../../partials/footer.php'; ?>
