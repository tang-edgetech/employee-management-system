<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Auth\TwoFactorDevices;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;

/**
 * Second step of sign-in: a code from any of the user's confirmed authenticator
 * devices, or one of their recovery codes (each works once). On success the user is
 * signed in and the session ID is renewed.
 */
class TwoFactorChallengeController extends Controller
{
    public function __construct(private readonly StatefulGuard $guard) {}

    public function __invoke(Request $request, TwoFactorDevices $devices): Response
    {
        $request->validate([
            'code' => ['nullable', 'string', 'digits:6', 'required_without:recovery_code'],
            'recovery_code' => ['nullable', 'string', 'max:64', 'required_without:code'],
        ]);

        $user = User::query()->find($request->session()->get('login.id'));
        if (! $user instanceof User) {
            throw ValidationException::withMessages(['code' => ['Sign in again to continue.']]);
        }

        $valid = false;
        if ($request->filled('recovery_code')) {
            $code = collect($user->recoveryCodes())->first(fn (string $c) => hash_equals($c, (string) $request->input('recovery_code')));
            if ($code !== null) {
                $user->replaceRecoveryCode($code);
                $valid = true;
            }
        } else {
            $valid = $devices->verifyForLogin($user, (string) $request->input('code')) !== null;
        }

        if (! $valid) {
            event(new TwoFactorAuthenticationFailed($user));
            throw ValidationException::withMessages([
                $request->filled('recovery_code') ? 'recovery_code' : 'code' => ['That code is not valid.'],
            ]);
        }

        $remember = (bool) $request->session()->pull('login.remember', false);
        $request->session()->forget('login.id');
        event(new ValidTwoFactorAuthenticationCodeProvided($user));

        $this->guard->login($user, $remember);
        $request->session()->regenerate();

        return response()->noContent();
    }
}
