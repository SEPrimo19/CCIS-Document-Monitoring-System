<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Guard;

/**
 * Administrator landing. Feature areas (user accounts, document types,
 * requirements, monitoring board, reports, audit log) are built out in Phase 4.
 */
final class AdminController extends Controller
{
    public function dashboard(): void
    {
        Guard::requireRole('Administrator');

        $this->view('dashboard/admin', [
            'appName' => $this->config()['app']['name'],
            'user'    => Auth::user(),
        ]);
    }
}
