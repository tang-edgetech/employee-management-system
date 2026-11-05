<?php

namespace App\Domain\Shared\Crypto;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent cast for sensitive columns: `'nric' => Encrypted::class`.
 * Values are encrypted with the versioned data key; null stays null.
 * The audit logger redacts every attribute that uses this cast.
 *
 * @implements CastsAttributes<string, string>
 */
final class Encrypted implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : app(FieldEncrypter::class)->decrypt((string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : app(FieldEncrypter::class)->encrypt((string) $value);
    }
}
