<?php

namespace Database\Factories;

use App\Domain\Access\AccessRole;
use App\Domain\Access\RoleKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /** Meets the password rules (8+, mixed case, number, symbol). */
    public const PASSWORD = 'Secret#2026';

    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make(self::PASSWORD),
            'role_id' => fn () => AccessRole::idFor(RoleKey::Staff),
            'status' => User::STATUS_ACTIVE,
            'remember_token' => Str::random(10),
        ];
    }

    public function role(RoleKey $role): static
    {
        return $this->state(fn () => ['role_id' => AccessRole::idFor($role)]);
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    /** A user with one confirmed authenticator device and two known recovery codes. */
    public function withTwoFactor(): static
    {
        return $this->state(fn () => [
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-one', 'recovery-code-two'])),
        ])->afterCreating(function (User $user) {
            $user->twoFactorDevices()->create([
                'name' => 'Test phone',
                'secret' => app(TwoFactorAuthenticationProvider::class)->generateSecretKey(),
                'confirmed_at' => now(),
            ]);
        });
    }
}
