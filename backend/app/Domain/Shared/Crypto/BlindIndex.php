<?php

namespace App\Domain\Shared\Crypto;

use InvalidArgumentException;
use RuntimeException;

/**
 * Keyed one-way hash that lets the app find exact matches of an encrypted value
 * (e.g. "is this NRIC already an employee?") without storing it in plain text.
 *
 * Each column has its own context, so the same value in two columns gives two
 * unrelated hashes. Stored as CHAR(64) hex.
 */
final class BlindIndex
{
    public const NRIC = 'nric';

    public const PASSPORT = 'passport';

    public const EMAIL = 'email';

    public function __construct(private readonly string $key)
    {
        if (strlen($key) < 32) {
            throw new RuntimeException('MERIDIAN_BLIND_INDEX_KEY must be at least 32 bytes.');
        }
    }

    public function hash(string $context, string $value): ?string
    {
        $normalised = self::normalise($context, $value);

        return $normalised === '' ? null : hash_hmac('sha256', $context.'|'.$normalised, $this->key);
    }

    /** NRIC 900101-14-5524 and 900101145524 must give the same hash. */
    public static function normalise(string $context, string $value): string
    {
        return match ($context) {
            self::NRIC => preg_replace('/\D+/', '', $value) ?? '',
            self::PASSPORT => strtoupper(preg_replace('/[^0-9A-Za-z]+/', '', $value) ?? ''),
            self::EMAIL => mb_strtolower(trim($value)),
            default => throw new InvalidArgumentException("Unknown blind index context [{$context}]."),
        };
    }
}
