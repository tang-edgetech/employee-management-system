<?php

use Laravel\Fortify\Features;

/*
| Fortify provides the login, 2FA and password logic. Its own routes are switched
| off (FortifyServiceProvider) and re-registered under /api/v1/auth in
| routes/api.php, so every auth endpoint follows the API conventions.
*/

return [

    'guard' => 'web',

    'passwords' => 'users',

    'username' => 'email',

    'email' => 'email',

    'lowercase_usernames' => true,

    'home' => '/dashboard',

    'prefix' => 'api/v1/auth',

    'domain' => null,

    'middleware' => ['api'],

    // Login throttling is done by LoginThrottle (per email, with backoff) and the
    // "login" route limiter (per IP); the 2FA challenge uses the "two-factor" limiter.
    'limiters' => [
        'login' => null,
        'two-factor' => 'two-factor',
    ],

    // API only: the Next.js app renders every screen.
    'views' => false,

    'features' => [
        // No self-registration: accounts are created by HR or the installer.
        Features::resetPasswords(),
        Features::updatePasswords(),
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
            // Accept one 30-second step either side, for phones whose clock drifts (plan 01 §8).
            'window' => 1,
        ]),
        // Passkeys: not used for now (owner, 2026-11-05).
    ],

];
