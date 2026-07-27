<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Guard;

/**
 * /dashboard is a single, stable post-login landing URL that forwards to the
 * signed-in user's role-specific page (FR-4).
 */
final class DashboardController extends Controller
{
    private const LANDING = [
        'Secretary' => '/admin/dashboard',
        'Faculty'   => '/faculty/dashboard',
    ];

    public function index(): void
    {
        Guard::requireAuth();

        $role = Auth::user()['role_name'] ?? '';
        header('Location: ' . url(self::LANDING[$role] ?? '/login'));
        exit;
    }
}
