<?php

namespace App\Models;

use App\Domain\Access\AccessRole;
use App\Domain\Access\RoleKey;
use App\Domain\Auth\Notifications\ResetPasswordNotification;
use App\Domain\Settings\CompanySettings;
use App\Domain\Shared\HasPublicId;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * A login account. Changes to accounts are audited explicitly by the services
 * that make them (not with the Auditable trait), because Fortify also saves
 * users outside our transactions (e.g. when a recovery code is used).
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $email
 * @property int $role_id
 * @property ?int $employee_id
 * @property string $status
 * @property ?string $two_factor_secret
 * @property ?Carbon $two_factor_confirmed_at
 * @property string $ui_layout
 * @property ?Carbon $last_login_at
 * @property ?Carbon $password_changed_at
 * @property int $version
 * @property-read AccessRole $role
 */
#[Fillable(['name', 'email', 'password', 'role_id', 'status', 'ui_layout'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasPublicId, Notifiable, TwoFactorAuthenticatable;

    public const STATUS_INVITED = 'invited';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public static function publicIdType(): string
    {
        return 'user';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    /** Emails are stored lower-case (a CHECK constraint enforces it). */
    public function setEmailAttribute(string $value): void
    {
        $this->attributes['email'] = mb_strtolower(trim($value));
    }

    /** @return BelongsTo<AccessRole, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(AccessRole::class, 'role_id');
    }

    public function roleKey(): RoleKey
    {
        return $this->role->roleKey();
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /** True when company settings force this user's role to use 2FA. */
    public function twoFactorEnforced(?CompanySettings $settings = null): bool
    {
        $settings ??= CompanySettings::current();

        return $settings->two_fa_enabled && in_array($this->roleKey()->value, $settings->two_fa_roles, true);
    }

    /** Enforced but not set up yet: the user may only set up 2FA or sign out. */
    public function mustSetUpTwoFactor(?CompanySettings $settings = null): bool
    {
        return ! $this->hasTwoFactorEnabled() && $this->twoFactorEnforced($settings);
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
