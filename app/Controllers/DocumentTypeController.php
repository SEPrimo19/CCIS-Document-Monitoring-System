<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Guard;
use App\Models\AuditLog;
use App\Models\DocumentType;

/**
 * Administrator document-type management (FR-27): create, list, edit, and
 * deactivate/reactivate document types. Deactivation is a soft-delete
 * (is_active), never a row delete, since requirements reference doc_type_id.
 */
final class DocumentTypeController extends Controller
{
    public function index(): void
    {
        Guard::requireRole('Administrator');

        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        $this->view('admin/document-types/index', [
            'appName'       => $this->config()['app']['name'],
            'documentTypes' => DocumentType::all(),
            'flash'         => $flash,
            'csrf'          => Csrf::token(),
        ]);
    }

    public function create(): void
    {
        Guard::requireRole('Administrator');

        $this->renderForm(null, '', '', []);
    }

    public function store(): void
    {
        Guard::requireRole('Administrator');

        [$name, $description] = $this->inputFrom($_POST);
        $token = (string) ($_POST['csrf_token'] ?? '');

        if (!Csrf::verify($token)) {
            $this->renderForm(null, $name, $description, ['_csrf' => 'Your session has expired. Please try again.']);
            return;
        }

        $errors = $this->validate($name, $description, null);
        if ($errors !== []) {
            $this->renderForm(null, $name, $description, $errors);
            return;
        }

        $adminId = (int) (Auth::user()['user_id'] ?? 0);
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        $newId = DocumentType::create($name, $this->nullableDescription($description), $adminId);
        AuditLog::record($adminId, 'doc_type_create', 'document_type', $newId, $name, $ip);

        $this->flash('ok', 'Document type "' . $name . '" was created.');
        $this->redirectToIndex();
    }

    public function edit(string $id): void
    {
        Guard::requireRole('Administrator');

        $type = DocumentType::find((int) $id);
        if ($type === null) {
            $this->notFoundPage();
            return;
        }

        $this->renderForm($type, $type['name'], (string) ($type['description'] ?? ''), []);
    }

    public function update(string $id): void
    {
        Guard::requireRole('Administrator');

        $docTypeId = (int) $id;
        $type = DocumentType::find($docTypeId);
        if ($type === null) {
            $this->notFoundPage();
            return;
        }

        [$name, $description] = $this->inputFrom($_POST);
        $token = (string) ($_POST['csrf_token'] ?? '');

        if (!Csrf::verify($token)) {
            $this->renderForm($type, $name, $description, ['_csrf' => 'Your session has expired. Please try again.']);
            return;
        }

        $errors = $this->validate($name, $description, $docTypeId);
        if ($errors !== []) {
            $this->renderForm($type, $name, $description, $errors);
            return;
        }

        DocumentType::update($docTypeId, $name, $this->nullableDescription($description));

        $adminId = (int) (Auth::user()['user_id'] ?? 0);
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        AuditLog::record($adminId, 'doc_type_update', 'document_type', $docTypeId, $name, $ip);

        $this->flash('ok', 'Document type "' . $name . '" was updated.');
        $this->redirectToIndex();
    }

    public function deactivate(string $id): void
    {
        Guard::requireRole('Administrator');

        $this->toggleActive($id, false, 'doc_type_deactivate');
    }

    public function activate(string $id): void
    {
        Guard::requireRole('Administrator');

        $this->toggleActive($id, true, 'doc_type_activate');
    }

    private function toggleActive(string $id, bool $active, string $action): void
    {
        $docTypeId = (int) $id;
        $type = DocumentType::find($docTypeId);
        if ($type === null) {
            $this->notFoundPage();
            return;
        }

        $token = (string) ($_POST['csrf_token'] ?? '');
        if (!Csrf::verify($token)) {
            $this->flash('err', 'Invalid request. Please try again.');
            $this->redirectToIndex();
            return;
        }

        DocumentType::setActive($docTypeId, $active);

        $adminId = (int) (Auth::user()['user_id'] ?? 0);
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        AuditLog::record($adminId, $action, 'document_type', $docTypeId, $type['name'], $ip);

        $this->flash('ok', 'Document type "' . $type['name'] . '" was ' . ($active ? 'reactivated' : 'deactivated') . '.');
        $this->redirectToIndex();
    }

    /**
     * @return array{0:string,1:string} [name, description] trimmed, straight from the request.
     */
    private function inputFrom(array $post): array
    {
        $name = trim((string) ($post['name'] ?? ''));
        $description = trim((string) ($post['description'] ?? ''));

        return [$name, $description];
    }

    private function nullableDescription(string $description): ?string
    {
        return $description === '' ? null : $description;
    }

    /**
     * @return array<string,string> field => error message; empty when valid.
     */
    private function validate(string $name, string $description, ?int $exceptId): array
    {
        $errors = [];

        $length = mb_strlen($name);
        if ($name === '') {
            $errors['name'] = 'Name is required.';
        } elseif ($length < 2 || $length > 80) {
            $errors['name'] = 'Name must be between 2 and 80 characters.';
        } elseif (DocumentType::existsByName($name, $exceptId)) {
            $errors['name'] = 'A document type with this name already exists.';
        }

        if (mb_strlen($description) > 255) {
            $errors['description'] = 'Description must be 255 characters or fewer.';
        }

        return $errors;
    }

    /**
     * @param array{doc_type_id:int,name:string,description:?string,is_active:int,created_by:?int,created_at:string}|null $type
     * @param array<string,string> $errors
     */
    private function renderForm(?array $type, string $name, string $description, array $errors): void
    {
        $this->view('admin/document-types/form', [
            'appName'     => $this->config()['app']['name'],
            'docType'     => $type,
            'name'        => $name,
            'description' => $description,
            'errors'      => $errors,
            'csrf'        => Csrf::token(),
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
        header('Location: ' . url('/admin/document-types'));
        exit;
    }
}
