<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sensitive account changes (2FA setup, recovery codes) need the password to
 * have been confirmed recently via POST /api/v1/auth/confirm-password.
 * Answers 423 PASSWORD_CONFIRMATION_REQUIRED so the app can ask for it.
 */
class RequireRecentPassword
{
    public function handle(Request $request, Closure $next): Response
    {
        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);

        if (now()->getTimestamp() - $confirmedAt > (int) config('auth.password_timeout', 900)) {
            throw new ApiProblem(423, 'PASSWORD_CONFIRMATION_REQUIRED', 'Password confirmation required', 'Confirm your password to continue.');
        }

        return $next($request);
    }
}
