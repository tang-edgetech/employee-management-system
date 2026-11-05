<?php

use App\Domain\Auth\LoginThrottle;
use App\Domain\Settings\CompanySettings;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->withHeaders(fromWebApp());
    $this->user = User::factory()->create(['email' => 'nadia@hajimi.my']);
});

function attemptLogin($test, string $email, string $password = UserFactory::PASSWORD)
{
    return $test->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
}

it('signs in an active user and exposes them on /me', function () {
    attemptLogin($this, 'nadia@hajimi.my')->assertOk()->assertExactJson(['two_factor' => false]);

    $this->assertAuthenticatedAs($this->user);
    $this->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.id', $this->user->public_id)
        ->assertJsonPath('data.role.key', 'staff')
        ->assertJsonPath('data.two_factor.setup_required', false);

    expect($this->user->fresh()->last_login_at)->not->toBeNull();
});

it('matches the email case-insensitively', function () {
    attemptLogin($this, '  NADIA@Hajimi.MY ')->assertOk();
});

it('rejects a wrong password with a field error', function () {
    attemptLogin($this, 'nadia@hajimi.my', 'Wrong#2026')
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['errors' => ['email']]);

    $this->assertGuest();
});

it('requires sign-in for /me', function () {
    $this->getJson('/api/v1/me')->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
});

it('locks the email after 5 failures, with exponential backoff', function () {
    foreach (range(1, 5) as $i) {
        attemptLogin($this, 'nadia@hajimi.my', 'Wrong#2026')->assertStatus(422);
    }

    // Even the right password is refused while locked.
    attemptLogin($this, 'nadia@hajimi.my')
        ->assertStatus(429)
        ->assertJsonPath('code', 'LOGIN_LOCKED')
        ->assertHeader('Retry-After', '60');

    $this->travel(61)->seconds();
    attemptLogin($this, 'nadia@hajimi.my', 'Wrong#2026')->assertStatus(422);
    attemptLogin($this, 'nadia@hajimi.my')->assertStatus(429)->assertHeader('Retry-After', '120');

    $this->travel(121)->seconds();
    attemptLogin($this, 'nadia@hajimi.my')->assertOk();

    // Success clears the count: the next failure doesn't lock.
    $this->postJson('/api/v1/auth/logout')->assertNoContent();
    attemptLogin($this, 'nadia@hajimi.my', 'Wrong#2026')->assertStatus(422);
    attemptLogin($this, 'nadia@hajimi.my')->assertOk();
});

it('doubles the lock up to one hour', function () {
    expect(LoginThrottle::lockSecondsAfter(4))->toBe(0)
        ->and(LoginThrottle::lockSecondsAfter(5))->toBe(60)
        ->and(LoginThrottle::lockSecondsAfter(6))->toBe(120)
        ->and(LoginThrottle::lockSecondsAfter(10))->toBe(1920)
        ->and(LoginThrottle::lockSecondsAfter(11))->toBe(3600)
        ->and(LoginThrottle::lockSecondsAfter(50))->toBe(3600);
});

it('treats unknown emails exactly like real ones, so accounts cannot be discovered', function () {
    $real = [];
    $unknown = [];
    foreach (range(1, 6) as $i) {
        $real[] = attemptLogin($this, 'nadia@hajimi.my', 'Wrong#2026')->status();
        $unknown[] = attemptLogin($this, 'nobody@hajimi.my', 'Wrong#2026')->status();
    }

    expect($unknown)->toBe($real)->and($real)->toBe([422, 422, 422, 422, 422, 429]);
});

it('refuses disabled and invited accounts, but only after a correct password', function () {
    $disabled = User::factory()->status(User::STATUS_DISABLED)->create(['email' => 'gone@hajimi.my']);
    $invited = User::factory()->status(User::STATUS_INVITED)->create(['email' => 'new@hajimi.my']);

    attemptLogin($this, $disabled->email, 'Wrong#2026')->assertStatus(422);
    attemptLogin($this, $disabled->email)->assertStatus(403)->assertJsonPath('code', 'ACCOUNT_DISABLED');
    attemptLogin($this, $invited->email)->assertStatus(403)->assertJsonPath('code', 'ACCOUNT_NOT_ACTIVE');

    $this->assertGuest();
});

it('signs out a user who is disabled mid-session', function () {
    attemptLogin($this, 'nadia@hajimi.my')->assertOk();
    $this->getJson('/api/v1/me')->assertOk();

    $this->user->forceFill(['status' => User::STATUS_DISABLED])->save();
    // A real request loads the user from the database; the test client would reuse the cached one.
    app('auth')->forgetGuards();

    $this->getJson('/api/v1/me')->assertStatus(401)->assertJsonPath('code', 'ACCOUNT_DISABLED');
});

it('signs out after the idle timeout from settings', function () {
    attemptLogin($this, 'nadia@hajimi.my')->assertOk();
    $this->getJson('/api/v1/me')->assertOk();

    $this->travel(29)->minutes();
    $this->getJson('/api/v1/me')->assertOk();

    $this->travel(31)->minutes();
    $this->getJson('/api/v1/me')->assertStatus(401)->assertJsonPath('code', 'SESSION_EXPIRED');
});

it('uses the timeout configured in company settings', function () {
    DB::table('company_settings')->update(['session_timeout_minutes' => 15]);
    attemptLogin($this, 'nadia@hajimi.my')->assertOk();
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.session_timeout_minutes', 15);

    $this->travel(16)->minutes();
    $this->getJson('/api/v1/me')->assertStatus(401)->assertJsonPath('code', 'SESSION_EXPIRED');

    expect(CompanySettings::current()->session_timeout_minutes)->toBe(15);
});

it('gives the browser a new session ID at login and destroys the old one', function () {
    $cookie = config('session.cookie');

    $sessionId = fn ($response) => CookieValuePrefix::remove(decrypt($response->getCookie($cookie, false)->getValue(), false));

    $oldId = $sessionId($this->get('/sanctum/csrf-cookie')->assertNoContent());
    expect(DB::table('sessions')->where('id', $oldId)->exists())->toBeTrue();

    // JSON test requests only send cookies with credentials, like a browser's fetch.
    $newId = $sessionId($this->withCredentials()->withCookie($cookie, $oldId)
        ->postJson('/api/v1/auth/login', ['email' => 'nadia@hajimi.my', 'password' => UserFactory::PASSWORD])
        ->assertOk());

    expect($newId)->not->toBe($oldId)
        ->and(DB::table('sessions')->where('id', $oldId)->exists())->toBeFalse();
});

it('rate limits sign-in attempts per IP across emails', function () {
    foreach (range(1, 20) as $i) {
        attemptLogin($this, "person{$i}@hajimi.my", 'Wrong#2026');
    }

    attemptLogin($this, 'nadia@hajimi.my')->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED');
});

it('logs out', function () {
    attemptLogin($this, 'nadia@hajimi.my')->assertOk();

    $this->postJson('/api/v1/auth/logout')->assertNoContent();

    $this->assertGuest('web');
});

it('gives every user a public ID and retries on a collision', function () {
    expect($this->user->public_id)->toMatch('/^usr_[0-9A-Za-z]{8}$/');

    $queue = [$this->user->public_id, 'usr_Fresh123'];
    User::generatePublicIdsUsing(function () use (&$queue) {
        return array_shift($queue);
    });

    try {
        expect(User::factory()->create()->public_id)->toBe('usr_Fresh123');
    } finally {
        User::generatePublicIdsUsing(null);
    }
});
