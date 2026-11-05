<?php

namespace App\Domain\Shared;

use Closure;
use RuntimeException;

/**
 * Gives a model a public_id on create and makes route model binding use it.
 * The model declares its type with a `publicIdType` key from config/meridian.php.
 *
 * @property string $public_id
 */
trait HasPublicId
{
    /** Test hook: replaces the random generator. */
    protected static ?Closure $publicIdGenerator = null;

    public static function bootHasPublicId(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->public_id)) {
                $model->public_id = static::newUniquePublicId();
            }
        });
    }

    public static function newUniquePublicId(): string
    {
        $prefix = config('meridian.public_id.prefixes.'.static::publicIdType());
        if (! is_string($prefix)) {
            throw new RuntimeException('No public ID prefix configured for ['.static::publicIdType().'].');
        }

        $attempts = (int) config('meridian.public_id.max_attempts', 5);
        for ($i = 0; $i < $attempts; $i++) {
            $candidate = static::$publicIdGenerator
                ? (static::$publicIdGenerator)($prefix)
                : PublicId::generate($prefix);

            if (! static::query()->where('public_id', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException("Could not generate a unique public ID with prefix [{$prefix}].");
    }

    public static function generatePublicIdsUsing(?Closure $generator): void
    {
        static::$publicIdGenerator = $generator;
    }

    abstract public static function publicIdType(): string;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
