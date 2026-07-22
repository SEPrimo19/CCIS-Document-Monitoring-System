<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Guard;
use App\Models\Notification;

/**
 * In-app notification center (FR-22) + the unread indicator (FR-23). Any
 * authenticated user may have notifications — faculty, reviewer, or admin —
 * so these actions only require Guard::requireAuth(), not a specific role.
 */
final class NotificationController extends Controller
{
    public function index(): void
    {
        Guard::requireAuth();

        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        $userId = (int) (Auth::user()['user_id'] ?? 0);

        $this->view('notifications/index', [
            'appName'       => $this->config()['app']['name'],
            'notifications' => Notification::forUser($userId),
            'flash'         => $flash,
            'csrf'          => Csrf::token(),
        ]);
    }

    public function markRead(string $id): void
    {
        Guard::requireAuth();

        $token = (string) ($_POST['csrf_token'] ?? '');
        if (!Csrf::verify($token)) {
            $this->flash('err', 'Your session has expired. Please try again.');
            $this->redirectToIndex();
            return;
        }

        $userId = (int) (Auth::user()['user_id'] ?? 0);
        Notification::markRead((int) $id, $userId);

        $this->redirectToIndex();
    }

    public function markAllRead(): void
    {
        Guard::requireAuth();

        $token = (string) ($_POST['csrf_token'] ?? '');
        if (!Csrf::verify($token)) {
            $this->flash('err', 'Your session has expired. Please try again.');
            $this->redirectToIndex();
            return;
        }

        $userId = (int) (Auth::user()['user_id'] ?? 0);
        Notification::markAllRead($userId);

        $this->flash('ok', 'All notifications marked as read.');
        $this->redirectToIndex();
    }

    private function flash(string $type, string $message): void
    {
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    }

    private function redirectToIndex(): void
    {
        header('Location: ' . url('/notifications'));
        exit;
    }
}
