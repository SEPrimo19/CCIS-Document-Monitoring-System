<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Guard;

/**
 * Reviewer/Approver landing. Feature areas (review queue, decisions, faculty
 * compliance view) are built out in Phase 4.
 */
final class ReviewerController extends Controller
{
    public function dashboard(): void
    {
        Guard::requireRole('Reviewer/Approver');

        $this->view('dashboard/reviewer', [
            'appName' => $this->config()['app']['name'],
            'user'    => Auth::user(),
        ]);
    }
}
