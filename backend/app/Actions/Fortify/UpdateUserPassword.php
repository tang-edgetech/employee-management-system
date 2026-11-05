<?php

namespace App\Actions\Fortify;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\Sessions;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

class UpdateUserPassword implements UpdatesUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and update the signed-in user's password. Their other sessions
     * end; the current one continues.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => [...$this->passwordRules(), 'different:current_password'],
        ], [
            'current_password.current_password' => __('The provided password does not match your current password.'),
        ])->validate();

        DB::transaction(function () use ($user, $input) {
            $user->forceFill([
                'password' => Hash::make($input['password']),
                'password_changed_at' => now(),
                'remember_token' => Str::random(60),
            ])->save();

            app(AuditLogger::class)->record('user.password_changed', $user);
        });

        Sessions::endAllFor($user, request()->hasSession() ? request()->session()->getId() : null);
    }
}
