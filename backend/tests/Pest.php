<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Every suite boots the Laravel app. Database and Feature tests run against the
| meridian_testing MariaDB database (phpunit.xml), rebuilt by RefreshDatabase.
|
*/

pest()->extend(TestCase::class)->in('Unit');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Database', 'Feature');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/** Headers that make Sanctum treat a request as coming from the web app (session + CSRF). */
function fromWebApp(): array
{
    return ['Origin' => 'http://meridian.localhost', 'Referer' => 'http://meridian.localhost/'];
}

/** The authenticator-app code for a user's 2FA secret, optionally some 30-second steps ago. */
function totpFor(User $user, int $stepsAgo = 0): string
{
    $google2fa = new Google2FA;
    $secret = decrypt((string) $user->fresh()?->two_factor_secret);

    return $google2fa->oathTotp($secret, (int) floor(now()->getTimestamp() / 30) - $stepsAgo);
}
