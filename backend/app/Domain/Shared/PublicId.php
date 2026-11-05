<?php

namespace App\Domain\Shared;

use InvalidArgumentException;

/**
 * Generates public IDs such as "emp_7Kq2xP9a": a type prefix plus random base62
 * characters (ADR 0001 §5.11). Stored as CHAR(16) ascii_bin, so they are
 * case-sensitive and at most 16 characters long.
 */
final class PublicId
{
    public const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public const MAX_LENGTH = 16;

    public static function generate(string $prefix): string
    {
        $length = (int) config('meridian.public_id.length', 8);

        if (! preg_match('/^[a-z]{2,6}$/', $prefix) || strlen($prefix) + 1 + $length > self::MAX_LENGTH) {
            throw new InvalidArgumentException("Invalid public ID prefix [{$prefix}].");
        }

        $max = strlen(self::ALPHABET) - 1;
        $random = '';
        for ($i = 0; $i < $length; $i++) {
            $random .= self::ALPHABET[random_int(0, $max)];
        }

        return "{$prefix}_{$random}";
    }

    public static function isValid(string $value, ?string $prefix = null): bool
    {
        $length = (int) config('meridian.public_id.length', 8);
        $prefixPattern = $prefix === null ? '[a-z]{2,6}' : preg_quote($prefix, '/');

        return (bool) preg_match('/^'.$prefixPattern.'_[0-9A-Za-z]{'.$length.'}$/', $value);
    }
}
