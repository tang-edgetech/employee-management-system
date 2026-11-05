<?php

namespace App\Domain\Auth;

use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

/**
 * Login pipeline step (replaces Fortify's RedirectIfTwoFactorAuthenticatable, which
 * only knows one secret per user). After a correct password, a user with at least one
 * confirmed authenticator device is not signed in yet: we remember who they are in
 * the session and answer {two_factor: true}; TwoFactorChallengeController finishes.
 */
final class RequireTwoFactorChallenge
{
    public function __construct(private readonly CredentialsCheck $credentials) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $user = ($this->credentials)($request);

        if (! $user instanceof User) {
            throw ValidationException::withMessages([Fortify::username() => [trans('auth.failed')]]);
        }

        if (! $user->hasTwoFactorEnabled()) {
            return $next($request);
        }

        $request->session()->put([
            'login.id' => $user->getKey(),
            'login.remember' => $request->boolean('remember'),
        ]);

        return new JsonResponse(['two_factor' => true]);
    }
}
