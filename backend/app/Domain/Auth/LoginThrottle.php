<?php

namespace App\Domain\Auth;

use App\Exceptions\ApiProblem;
use Illuminate\Support\Facades\Cache;

/**
 * Locks an email address out after repeated failed logins (brief §4): the 5th
 * failure locks it for 1 minute, and each further failure doubles the lock, up
 * to 1 hour. A successful login clears the count.
 *
 * Keyed by the email as typed, whether or not an account exists, so the
 * responses can't be used to discover which emails have accounts.
 */
final class LoginThrottle
{
    public const FREE_ATTEMPTS = 4;

    public const BASE_LOCK_SECONDS = 60;

    public const MAX_LOCK_SECONDS = 3600;

    private const MEMORY_HOURS = 24;

    public function ensureNotLocked(string $email): void
    {
        $lockedUntil = (int) ($this->state($email)['locked_until'] ?? 0);
        $remaining = $lockedUntil - now()->getTimestamp();

        if ($remaining > 0) {
            throw new ApiProblem(
                429,
                'LOGIN_LOCKED',
                'Too many failed sign-in attempts',
                'Sign-in for this account is paused. Try again in '.self::humanSeconds($remaining).'.',
                ['retry_after' => $remaining],
                ['Retry-After' => (string) $remaining],
            );
        }
    }

    public function recordFailure(string $email): void
    {
        $state = $this->state($email);
        $failures = (int) ($state['failures'] ?? 0) + 1;
        $lockedUntil = 0;

        if ($failures > self::FREE_ATTEMPTS) {
            $lockedUntil = now()->getTimestamp() + self::lockSecondsAfter($failures);
        }

        Cache::put($this->key($email), ['failures' => $failures, 'locked_until' => $lockedUntil], now()->addHours(self::MEMORY_HOURS));
    }

    public function clear(string $email): void
    {
        Cache::forget($this->key($email));
    }

    /** 5th failure: 60 s, 6th: 120 s, 7th: 240 s ... capped at 1 hour. */
    public static function lockSecondsAfter(int $failures): int
    {
        if ($failures <= self::FREE_ATTEMPTS) {
            return 0;
        }

        return min(self::MAX_LOCK_SECONDS, self::BASE_LOCK_SECONDS * 2 ** min(20, $failures - self::FREE_ATTEMPTS - 1));
    }

    /** @return array{failures?: int, locked_until?: int} */
    private function state(string $email): array
    {
        $state = Cache::get($this->key($email));

        return is_array($state) ? $state : [];
    }

    private function key(string $email): string
    {
        return 'login-throttle:'.hash('sha256', mb_strtolower(trim($email)));
    }

    private static function humanSeconds(int $seconds): string
    {
        return $seconds < 60 ? $seconds.' seconds' : (int) ceil($seconds / 60).' minute'.((int) ceil($seconds / 60) === 1 ? '' : 's');
    }
}
