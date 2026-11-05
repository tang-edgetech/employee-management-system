<?php

namespace App\Http\Middleware;

use App\Domain\Settings\CompanySettings;
use App\Exceptions\ApiProblem;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * For signed-in browser sessions: ends the session when the account is no longer
 * active, or when it has been idle longer than the company's session timeout
 * (brief §4, setting session_timeout_minutes).
 */
class EnsureSessionIsValid
{
    public const LAST_ACTIVITY_KEY = 'meridian.last_activity_at';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $request->hasSession()) {
            return $next($request);
        }

        if (! $user->isActive()) {
            $this->endSession($request);

            throw new ApiProblem(401, 'ACCOUNT_DISABLED', 'Signed out', 'This account is no longer active.');
        }

        $now = now()->getTimestamp();
        $last = $request->session()->get(self::LAST_ACTIVITY_KEY);
        $timeout = CompanySettings::current()->session_timeout_minutes * 60;

        if (is_int($last) && $now - $last > $timeout) {
            $this->endSession($request);

            throw new ApiProblem(401, 'SESSION_EXPIRED', 'Signed out', 'You were signed out after a period of inactivity. Sign in again.');
        }

        $request->session()->put(self::LAST_ACTIVITY_KEY, $now);

        return $next($request);
    }

    private function endSession(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
