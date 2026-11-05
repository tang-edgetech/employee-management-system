<?php

namespace App\Domain\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a user's stored sessions after a password change or reset, so they
 * stop at once. Only possible with the database session driver; with any driver,
 * Sanctum's AuthenticateSession middleware also signs out every other session on
 * its next request, because the password hash it remembers no longer matches.
 */
final class Sessions
{
    public static function endAllFor(User $user, ?string $exceptSessionId = null): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->when($exceptSessionId, fn ($q) => $q->where('id', '!=', $exceptSessionId))
            ->delete();
    }
}
