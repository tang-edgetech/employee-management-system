<?php

use App\Domain\Auth\TwoFactorDevice;
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

/**
 * The authenticator-app code for one of a user's devices (their first one by default),
 * optionally some 30-second steps ago.
 */
function totpFor(User $user, int $stepsAgo = 0, ?TwoFactorDevice $device = null): string
{
    $device ??= $user->twoFactorDevices()->orderBy('id')->firstOrFail();

    return totpForSecret($device->secret, $stepsAgo);
}

function totpForSecret(string $secret, int $stepsAgo = 0): string
{
    return (new Google2FA)->oathTotp($secret, (int) floor(now()->getTimestamp() / 30) - $stepsAgo);
}

/** A demo-company account by first name, e.g. demoUser('teechienyap'). Seed DemoCompanySeeder first. */
function demoUser(string $name): User
{
    return User::query()->where('email', 'like', $name.'@%')->firstOrFail();
}
