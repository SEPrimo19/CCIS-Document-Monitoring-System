<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\LoginThrottle;
use App\Models\AuditLog;
use App\Models\LoginAttempt; 

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

        $throttleEmail = Auth::normalizeEmail($email);
        $ip            = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        if (!Csrf::verify($token)) {
            $this->view('auth/login', [
                'appName' => $this->config()['app']['name'],
                'error'   => 'Invalid email or password.',
                'email'   => $email,
                'csrf'    => Csrf::token(),
            ]);
            return;
        }

        if (LoginThrottle::isLockedOut($throttleEmail, $ip)) {
            $this->view('auth/login', [
                'appName' => $this->config()['app']['name'],
                'error'   => 'Too many failed attempts. Please try again in a few minutes.',
                'email'   => $email,
                'csrf'    => Csrf::token(),
            ]);
            return;
        }

        if (Auth::attempt($email, $password)) {
            LoginAttempt::clearFailures($throttleEmail, $ip);

            $userId = Auth::user()['user_id'] ?? null;
            if ($userId !== null) {
                AuditLog::record($userId, 'login', 'user', $userId, null, $ip);
            }

            $this->redirectToDashboard();
            return;
        }

        LoginAttempt::record($throttleEmail, $ip, false);

        $this->view('auth/login', [
            'appName' => $this->config()['app']['name'],
            'error'   => 'Invalid email or password.',
            'email'   => $email,
            'csrf'    => Csrf::token(),
        ]);
    }

    public function logout(): void
    {
        $token = (string) ($_POST['csrf_token'] ?? '');
        if (Csrf::verify($token)) {
            Auth::logout();
        }

        header('Location: ' . url('/login'));
        exit;
    }

    /**
     * Every role lands on /dashboard, which then forwards to that role's page
     * (FR-4). Keeping one entry point here means the redirect target never
     * needs to be duplicated across controllers.
     */
    private function redirectToDashboard(): void
    {
        header('Location: ' . url('/dashboard'));
        exit;
    }
}
