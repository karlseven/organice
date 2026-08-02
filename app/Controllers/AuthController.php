<?php
declare(strict_types=1);

namespace Controllers;

use Core\Audit;
use Core\Auth;
use Core\Request;
use Core\Throttle;
use Core\View;

final class AuthController
{
    public function showLogin(): void
    {
        if (Auth::check()) redirect('/');
        View::render('auth/login', [
            'title' => 'Sign in',
            'next'  => Request::query('next', '/'),
            'error' => null,
        ]);
    }

    public function login(): void
    {
        $email = Request::post('email');
        $pass  = (string)($_POST['password'] ?? '');
        $next  = Request::post('next', '/');

        /* Checked before the password is even looked at, so a locked-out
           attacker gets no timing signal about whether the guess was close. */
        $wait = Throttle::retryAfter($email);
        if ($wait > 0) {
            http_response_code(429);
            header('Retry-After: ' . $wait);
            View::render('auth/login', [
                'title' => 'Sign in',
                'next'  => $next,
                'error' => 'Too many sign-in attempts. Try again in '
                         . ceil($wait / 60) . ' minutes.',
                'email' => $email,
            ]);
            return;
        }

        if (!Auth::attempt($email, $pass)) {
            /* One message for every failure — wrong address, wrong password,
               deactivated account. Naming which one is wrong tells an attacker
               which half of the guess to keep. */
            http_response_code(422);
            View::render('auth/login', [
                'title' => 'Sign in',
                'next'  => $next,
                'error' => 'Those details do not match an account.',
                'email' => $email,
            ]);
            return;
        }

        /* Only same-site paths: `next` comes from the query string, so an
           absolute URL here would make the login form an open redirect. */
        redirect(str_starts_with($next, '/') && !str_starts_with($next, '//') ? $next : '/');
    }

    public function logout(): void
    {
        Audit::log('logout');
        Auth::logout();
        redirect('/');
    }
}
