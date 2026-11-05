<?php

use App\Domain\Access\RoleKey;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

beforeEach(function () {
    $this->withHeaders(fromWebApp());
});

function signIn($test, User $user)
{
    return $test->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD]);
}

function confirmPassword($test)
{
    return $test->postJson('/api/v1/auth/confirm-password', ['password' => UserFactory::PASSWORD]);
}

/** Turns on 2FA (one confirmed device, two known recovery codes) for an existing user. */
function giveTwoFactor(User $user): void
{
    $user->forceFill(['two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-one', 'recovery-code-two']))])->save();
    $user->twoFactorDevices()->create([
        'name' => 'Test phone',
        'secret' => app(TwoFactorAuthenticationProvider::class)->generateSecretKey(),
        'confirmed_at' => now(),
    ]);
    // Next request reloads the signed-in user from the database, as a real one would.
    app('auth')->forgetGuards();
}

/** Adds and confirms an authenticator device through the API; returns its secret. */
function addDevice($test, string $name): string
{
    $setup = $test->postJson('/api/v1/auth/2fa/devices', ['name' => $name])->assertCreated();
    $test->postJson("/api/v1/auth/2fa/devices/{$setup->json('data.id')}/confirm", ['code' => totpForSecret($setup->json('data.secret'))])->assertOk();

    return $setup->json('data.secret');
}

function enforceTwoFactorFor(array $roles): void
{
    DB::table('company_settings')->update(['two_fa_enabled' => true, 'two_fa_roles' => json_encode($roles)]);
}

describe('signing in with 2FA', function () {
    beforeEach(function () {
        $this->user = User::factory()->withTwoFactor()->create();
    });

    it('asks for a code instead of signing in', function () {
        signIn($this, $this->user)->assertOk()->assertExactJson(['two_factor' => true]);

        $this->assertGuest();
    });

    it('signs in with a valid authenticator code', function () {
        signIn($this, $this->user);

        $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => totpFor($this->user)])->assertNoContent();

        $this->assertAuthenticatedAs($this->user);
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.two_factor.enabled', true);
    });

    it('rejects a wrong code', function () {
        signIn($this, $this->user);

        $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => '000000'])->assertStatus(422);

        $this->assertGuest();
    });

    it('accepts a code from one step ago (phone clock drift) but not two', function () {
        signIn($this, $this->user);
        $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => totpFor($this->user, 2)])->assertStatus(422);
        $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => totpFor($this->user, 1)])->assertNoContent();
    });

    it('accepts each recovery code only once', function () {
        signIn($this, $this->user);
        $this->postJson('/api/v1/auth/two-factor-challenge', ['recovery_code' => 'recovery-code-one'])->assertNoContent();
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        // A real next request starts with no cached guards; the test client keeps Sanctum's.
        app('auth')->forgetGuards();

        signIn($this, $this->user);
        $this->postJson('/api/v1/auth/two-factor-challenge', ['recovery_code' => 'recovery-code-one'])->assertStatus(422);
        $this->assertGuest('web');
    });

    it('cannot skip the challenge by calling the API directly', function () {
        signIn($this, $this->user);

        $this->getJson('/api/v1/me')->assertStatus(401);
    });
});

describe('setting up 2FA', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        signIn($this, $this->user)->assertOk();
    });

    it('needs a recently confirmed password', function () {
        $this->postJson('/api/v1/auth/2fa/devices', ['name' => 'Phone'])->assertStatus(423)->assertJsonPath('code', 'PASSWORD_CONFIRMATION_REQUIRED');

        confirmPassword($this)->assertCreated();
        $this->postJson('/api/v1/auth/2fa/devices', ['name' => 'Phone'])->assertCreated();

        $this->travel(16)->minutes();
        $this->postJson('/api/v1/auth/2fa/devices', ['name' => 'Phone'])->assertStatus(423);
    });

    it('returns a secret and QR code, then turns on after a valid code with recovery codes', function () {
        confirmPassword($this);

        $setup = $this->postJson('/api/v1/auth/2fa/devices', ['name' => 'My phone'])->assertCreated();
        expect($setup->json('data.secret'))->toMatch('/^[A-Z2-7]{16,}$/')
            ->and($setup->json('data.qr_svg'))->toContain('<svg')
            ->and($setup->json('data.otpauth_url'))->toStartWith('otpauth://totp/');
        expect($this->user->fresh()->hasTwoFactorEnabled())->toBeFalse();

        $id = $setup->json('data.id');
        $this->postJson("/api/v1/auth/2fa/devices/{$id}/confirm", ['code' => '000000'])->assertStatus(422)->assertJsonPath('code', 'TWO_FACTOR_CODE_INVALID');

        $codes = $this->postJson("/api/v1/auth/2fa/devices/{$id}/confirm", ['code' => totpForSecret($setup->json('data.secret'))])
            ->assertOk()->json('data.recovery_codes');

        expect($codes)->toHaveCount(8)
            ->and($this->user->fresh()->hasTwoFactorEnabled())->toBeTrue()
            ->and(DB::table('audit_log')->where('action', 'user.two_factor_device_added')->count())->toBe(1);

        $this->getJson('/api/v1/auth/2fa/devices')->assertOk()
            ->assertJsonPath('data.enabled', true)->assertJsonPath('data.max_devices', 2)
            ->assertJsonPath('data.devices.0.name', 'My phone');
    });

    it('allows two devices, gives recovery codes only for the first, and either one signs in', function () {
        confirmPassword($this);
        $first = addDevice($this, 'Phone');
        $second = $this->postJson('/api/v1/auth/2fa/devices', ['name' => 'Tablet'])->assertCreated();
        $this->postJson("/api/v1/auth/2fa/devices/{$second->json('data.id')}/confirm", ['code' => totpForSecret($second->json('data.secret'))])
            ->assertOk()->assertJsonPath('data.recovery_codes', null);

        $this->postJson('/api/v1/auth/2fa/devices', ['name' => 'Third'])->assertStatus(409)->assertJsonPath('code', 'TWO_FACTOR_DEVICE_LIMIT');

        foreach ([$first, $second->json('data.secret')] as $secret) {
            $this->postJson('/api/v1/auth/logout')->assertNoContent();
            app('auth')->forgetGuards();
            signIn($this, $this->user)->assertExactJson(['two_factor' => true]);
            // Each code works once and was used to confirm the device; the next one is within the allowed drift.
            $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => totpForSecret($secret, -1)])->assertNoContent();
            $this->assertAuthenticatedAs($this->user);
        }
    });

    it('replaces an unfinished setup instead of counting it', function () {
        confirmPassword($this);
        $this->postJson('/api/v1/auth/2fa/devices', ['name' => 'Abandoned'])->assertCreated();
        $this->postJson('/api/v1/auth/2fa/devices', ['name' => 'Abandoned again'])->assertCreated();

        expect($this->user->twoFactorDevices()->count())->toBe(1);
    });

    it('never writes the secret or recovery codes to the audit log', function () {
        confirmPassword($this);
        $setup = $this->postJson('/api/v1/auth/2fa/devices', ['name' => 'Phone']);
        $codes = $this->postJson("/api/v1/auth/2fa/devices/{$setup->json('data.id')}/confirm", ['code' => totpForSecret($setup->json('data.secret'))])->json('data.recovery_codes');

        $log = DB::table('audit_log')->pluck('changes')->implode(' ');
        expect($log)->not->toContain($setup->json('data.secret'))->not->toContain($codes[0]);
    });

    it('cannot confirm or remove someone else\'s device', function () {
        $other = User::factory()->withTwoFactor()->create();
        $device = $other->twoFactorDevices()->firstOrFail();
        confirmPassword($this);

        $this->postJson("/api/v1/auth/2fa/devices/{$device->public_id}/confirm", ['code' => totpFor($other)])->assertNotFound();
        $this->deleteJson("/api/v1/auth/2fa/devices/{$device->public_id}")->assertNotFound();
        expect($device->fresh())->not->toBeNull();
    });

    it('regenerates recovery codes and the old ones stop working', function () {
        giveTwoFactor($this->user);
        confirmPassword($this);

        $this->getJson('/api/v1/auth/2fa/recovery-codes')->assertOk()->assertJsonPath('data.recovery_codes', ['recovery-code-one', 'recovery-code-two']);
        $new = $this->postJson('/api/v1/auth/2fa/recovery-codes')->assertOk()->json('data.recovery_codes');

        expect($new)->toHaveCount(8)->not->toContain('recovery-code-one')
            ->and(DB::table('audit_log')->where('action', 'user.two_factor_recovery_codes_regenerated')->count())->toBe(1);
    });

    it('turns 2FA off when the last device is removed and the role does not require it', function () {
        giveTwoFactor($this->user);
        confirmPassword($this);
        $device = $this->user->twoFactorDevices()->firstOrFail();

        $this->deleteJson("/api/v1/auth/2fa/devices/{$device->public_id}")->assertNoContent();

        expect($this->user->fresh()->hasTwoFactorEnabled())->toBeFalse()
            ->and($this->user->fresh()->two_factor_recovery_codes)->toBeNull()
            ->and(DB::table('audit_log')->where('action', 'user.two_factor_device_removed')->count())->toBe(1);
    });
});

describe('2FA required by role', function () {
    it('lets the user in but only to set up 2FA', function () {
        enforceTwoFactorFor(['staff']);
        $user = User::factory()->create();

        signIn($this, $user)->assertOk();

        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.two_factor.setup_required', true);
        $this->putJson('/api/v1/auth/password', [])->assertStatus(403)->assertJsonPath('code', 'TWO_FACTOR_SETUP_REQUIRED');

        confirmPassword($this)->assertCreated();
        addDevice($this, 'Phone');

        $this->getJson('/api/v1/me')->assertJsonPath('data.two_factor.setup_required', false);
        $this->putJson('/api/v1/auth/password', [])->assertStatus(422);
    });

    it('applies to users who are already signed in on their next request', function () {
        $user = User::factory()->create();
        signIn($this, $user)->assertOk();
        $this->putJson('/api/v1/auth/password', [])->assertStatus(422);

        enforceTwoFactorFor(['staff', 'hr']);

        $this->putJson('/api/v1/auth/password', [])->assertStatus(403)->assertJsonPath('code', 'TWO_FACTOR_SETUP_REQUIRED');
    });

    it('does not affect roles outside the list, or anyone when the switch is off', function () {
        $hr = User::factory()->role(RoleKey::Hr)->create();
        enforceTwoFactorFor(['staff']);
        signIn($this, $hr)->assertOk();
        $this->getJson('/api/v1/me')->assertJsonPath('data.two_factor.enforced', false);

        DB::table('company_settings')->update(['two_fa_enabled' => false, 'two_fa_roles' => json_encode(['hr'])]);
        $this->getJson('/api/v1/me')->assertJsonPath('data.two_factor.enforced', false);
    });

    it('cannot remove the last device while the role requires it, but can remove one of two', function () {
        enforceTwoFactorFor(['staff']);
        $user = User::factory()->withTwoFactor()->create();
        signIn($this, $user);
        $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => totpFor($user)])->assertNoContent();
        confirmPassword($this);
        $first = $user->twoFactorDevices()->firstOrFail();

        $this->deleteJson("/api/v1/auth/2fa/devices/{$first->public_id}")->assertStatus(409)->assertJsonPath('code', 'TWO_FACTOR_ENFORCED');

        addDevice($this, 'Backup phone');
        $this->deleteJson("/api/v1/auth/2fa/devices/{$first->public_id}")->assertNoContent();
        expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();
    });
});
