<?php

namespace App\Domain\Auth;

use App\Exceptions\ApiProblem;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Fortify's authenticateUsing callback: checks email + password, counts failures
 * for the lockout, and refuses accounts that aren't active.
 *
 * The account status is only revealed after a correct password, so it tells an
 * attacker nothing.
 */
final class CredentialsCheck
{
    public function __construct(private readonly LoginThrottle $throttle) {}

    public function __invoke(Request $request): ?User
    {
        $email = mb_strtolower(trim((string) $request->input('email')));
        $user = User::query()->where('email', $email)->first();

        if ($user === null || ! Hash::check((string) $request->input('password'), $user->password)) {
            $this->throttle->recordFailure($email);

            return null;
        }

        $this->throttle->clear($email);

        if ($user->status === User::STATUS_DISABLED) {
            throw new ApiProblem(403, 'ACCOUNT_DISABLED', 'Account disabled', 'This account has been disabled. Contact HR if you think this is a mistake.');
        }
        if ($user->status !== User::STATUS_ACTIVE) {
            throw new ApiProblem(403, 'ACCOUNT_NOT_ACTIVE', 'Account not activated', 'Finish setting up your account from the invitation email first.');
        }

        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => $request->input('password')])->saveQuietly();
        }

        return $user;
    }
}
