<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Guard;

/**
 * Faculty landing. Feature areas (My Requirements checklist, upload, My
 * Submissions, document detail) are built out in Phase 4.
 */
final class FacultyController extends Controller
{
    public function dashboard(): void
    {
        Guard::requireRole('Faculty');

        $this->view('dashboard/faculty', [
            'appName' => $this->config()['app']['name'],
            'user'    => Auth::user(),
        ]);
    }
}
