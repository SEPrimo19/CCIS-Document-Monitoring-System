<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;

/**
 * Login / logout. Implements FR-1 (validate credentials, reject with a
 * non-specific error) and FR-4 (establish a session, land on the role dashboard,
 * explicit logout).
 */
final class AuthController extends Controller
{
    public function showLogin(): void
    {
        if (Auth::check()) {
            $this->redirectToDashboard();
            return;
        }

        $this->view('auth/login', [
            'appName' => $this->config()['app']['name'],
            'error'   => null,
            'email'   => '',
            'csrf'    => Csrf::token(),
        ]);
    }

    public function login(): void
    {
        $email    = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $token    = (string) ($_POST['csrf_token'] ?? '');

        if (Csrf::verify($token) && Auth::attempt($email, $password)) {
            $this->redirectToDashboard();
            return;
        }

        $this->view('auth/login', [
            'appName' => $this->config()['app']['name'],
            'error'   => 'Invalid email or password.',
            'email'   => $email,
            'csrf'    => Csrf::token(),
        ]);
    }

    public function logout(): void
    {
        Auth::logout();
        header('Location: /login');
        exit;
    }

    /**
     * Every role lands on /dashboard, which then forwards to that role's page
     * (FR-4). Keeping one entry point here means the redirect target never
     * needs to be duplicated across controllers.
     */
    private function redirectToDashboard(): void
    {
        header('Location: /dashboard');
        exit;
    }
}
