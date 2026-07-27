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
        $filter = (($_GET['filter'] ?? '') === 'unread') ? 'unread' : 'all';

        // Both tab counts come from COUNT(*) over the whole table, and the
        // unread tab from its own query — never from filtering the capped page
        // below, which would make these disagree with the header badge once a
        // user has more than the page limit of notifications.
        $this->view('notifications/index', [
            'appName'       => $this->config()['app']['name'],
            'notifications' => $filter === 'unread'
                ? Notification::unreadForUser($userId)
                : Notification::forUser($userId),
            'filter'        => $filter,
            'totalCount'    => Notification::countForUser($userId),
            'unreadCount'   => Notification::unreadCount($userId),
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
            $this->redirectToIndex($this->postedFilter());
            return;
        }

        $userId = (int) (Auth::user()['user_id'] ?? 0);
        Notification::markRead((int) $id, $userId);

        $this->redirectToIndex($this->postedFilter());
    }

    /**
     * The tab the user acted from, round-tripped through a hidden field so
     * marking an item read on the "Unread" tab returns there instead of
     * silently bouncing the user back to "All".
     */
    private function postedFilter(): string
    {
        return (($_POST['filter'] ?? '') === 'unread') ? 'unread' : 'all';
    }

    public function markAllRead(): void
    {
        Guard::requireAuth();

        $token = (string) ($_POST['csrf_token'] ?? '');
        if (!Csrf::verify($token)) {
            $this->flash('err', 'Your session has expired. Please try again.');
            $this->redirectToIndex($this->postedFilter());
            return;
        }

        $userId = (int) (Auth::user()['user_id'] ?? 0);
        Notification::markAllRead($userId);

        $this->flash('ok', 'All notifications marked as read.');
        $this->redirectToIndex($this->postedFilter());
    }

    private function flash(string $type, string $message): void
    {
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    }

    private function redirectToIndex(string $filter = 'all'): void
    {
        header('Location: ' . url('/notifications' . ($filter === 'unread' ? '?filter=unread' : '')));
        exit;
    }
}
