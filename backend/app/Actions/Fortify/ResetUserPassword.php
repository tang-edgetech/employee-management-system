<?php

namespace App\Actions\Fortify;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\LoginThrottle;
use App\Domain\Auth\Sessions;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password. Every existing session
     * ends, and the email's failed-login count is cleared.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        DB::transaction(function () use ($user, $input) {
            $user->forceFill([
                'password' => Hash::make($input['password']),
                'password_changed_at' => now(),
                'remember_token' => Str::random(60),
            ])->save();

            app(AuditLogger::class)->record('user.password_reset', $user);
        });

        Sessions::endAllFor($user);
        app(LoginThrottle::class)->clear($user->email);
    }
}
