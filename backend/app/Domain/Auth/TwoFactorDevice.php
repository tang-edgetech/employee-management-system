<?php

namespace App\Domain\Auth;

use App\Domain\Shared\HasPublicId;
use App\Domain\Shared\Model;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An authenticator app registered by a user (plan 01 §9.2): at most two per user.
 * Unconfirmed devices can't be used to sign in.
 *
 * @property int $id
 * @property string $public_id
 * @property int $user_id
 * @property string $name
 * @property string $secret decrypted TOTP secret
 * @property ?Carbon $confirmed_at
 * @property ?Carbon $last_used_at
 * @property Carbon $created_at
 */
class TwoFactorDevice extends Model
{
    use HasPublicId;

    public const MAX_PER_USER = 2;

    protected $table = 'two_factor_devices';

    protected $guarded = ['id', 'public_id'];

    protected $hidden = ['secret'];

    public static function publicIdType(): string
    {
        return 'device';
    }

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'confirmed_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }
}
