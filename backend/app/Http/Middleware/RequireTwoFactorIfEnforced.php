<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiProblem;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When the company requires 2FA for a user's role and they haven't set it up,
 * every endpoint except 2FA setup, /me and logout answers 403, so the app sends
 * them to the setup screen. Applies on the next request after the setting
 * changes, even to users already signed in.
 */
class RequireTwoFactorIfEnforced
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->mustSetUpTwoFactor()) {
            throw new ApiProblem(403, 'TWO_FACTOR_SETUP_REQUIRED', 'Two-factor authentication required', 'Your role requires two-factor authentication. Set it up to continue.');
        }

        return $next($request);
    }
}
