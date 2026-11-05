<?php

use App\Domain\Auth\Notifications\ResetPasswordNotification;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

beforeEach(function () {
    $this->withHeaders(fromWebApp());
    $this->user = User::factory()->create(['email' => 'kevin@hajimi.my']);
});

function addSessionFor(User $user, string $id): void
{
    DB::table('sessions')->insert([
        'id' => $id, 'user_id' => $user->id, 'ip_address' => '10.0.0.9', 'user_agent' => 'Phone',
        'payload' => base64_encode(serialize([])), 'last_activity' => now()->getTimestamp(),
    ]);
}

describe('forgot password', function () {
    it('answers the same way for real, unknown and disabled accounts', function () {
        Notification::fake();
        User::factory()->status(User::STATUS_DISABLED)->create(['email' => 'gone@hajimi.my']);

        $bodies = collect(['kevin@hajimi.my', 'nobody@hajimi.my', 'gone@hajimi.my'])->map(
            fn ($email) => $this->postJson('/api/v1/auth/forgot-password', ['email' => $email])->assertStatus(202)->json()
        );

        expect($bodies->unique())->toHaveCount(1);
        Notification::assertSentTo($this->user, ResetPasswordNotification::class);
        Notification::assertCount(1);
    });

    it('queues the email after commit, with a link to the web app', function () {
        $notification = new ResetPasswordNotification('the-token');
        $mail = $notification->toMail($this->user);

        expect($notification)->toBeInstanceOf(ShouldQueue::class)
            ->and($notification->afterCommit)->toBeTrue()
            ->and($mail->actionUrl)->toBe('http://meridian.localhost/reset-password/?token=the-token&email=kevin%40hajimi.my');
    });
});

describe('reset password', function () {
    it('sets the new password, ends every session and is audited', function () {
        addSessionFor($this->user, str_repeat('a', 40));
        $token = Password::broker()->createToken($this->user);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token, 'email' => 'kevin@hajimi.my',
            'password' => 'NewPass#2026', 'password_confirmation' => 'NewPass#2026',
        ])->assertOk();

        expect(Hash::check('NewPass#2026', $this->user->fresh()->password))->toBeTrue()
            ->and(DB::table('sessions')->where('user_id', $this->user->id)->count())->toBe(0)
            ->and(DB::table('audit_log')->where('action', 'user.password_reset')->count())->toBe(1);

        // The token works only once.
        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token, 'email' => 'kevin@hajimi.my',
            'password' => 'Other#2026x', 'password_confirmation' => 'Other#2026x',
        ])->assertStatus(422);
    });

    it('enforces the password rules', function (string $password) {
        $token = Password::broker()->createToken($this->user);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token, 'email' => 'kevin@hajimi.my', 'password' => $password, 'password_confirmation' => $password,
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['password']]);
    })->with([
        'too short' => 'Ab#1xyz',
        'no upper case' => 'abcdef#1',
        'no lower case' => 'ABCDEF#1',
        'no number' => 'Abcdefg#',
        'no symbol' => 'Abcdefg1',
    ]);
});

describe('change password', function () {
    beforeEach(function () {
        $this->postJson('/api/v1/auth/login', ['email' => 'kevin@hajimi.my', 'password' => UserFactory::PASSWORD])->assertOk();
    });

    it('needs the current password', function () {
        $this->putJson('/api/v1/auth/password', [
            'current_password' => 'Wrong#2026', 'password' => 'NewPass#2026', 'password_confirmation' => 'NewPass#2026',
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['current_password']]);
    });

    it('must differ from the current password', function () {
        $this->putJson('/api/v1/auth/password', [
            'current_password' => UserFactory::PASSWORD, 'password' => UserFactory::PASSWORD, 'password_confirmation' => UserFactory::PASSWORD,
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['password']]);
    });

    it('changes it, ends other sessions, keeps this one and is audited', function () {
        addSessionFor($this->user, str_repeat('b', 40));

        $this->putJson('/api/v1/auth/password', [
            'current_password' => UserFactory::PASSWORD, 'password' => 'NewPass#2026', 'password_confirmation' => 'NewPass#2026',
        ])->assertOk();

        expect(Hash::check('NewPass#2026', $this->user->fresh()->password))->toBeTrue()
            ->and(DB::table('sessions')->where('id', str_repeat('b', 40))->exists())->toBeFalse()
            ->and(DB::table('audit_log')->where('action', 'user.password_changed')->count())->toBe(1);

        $this->getJson('/api/v1/me')->assertOk();
    });
});
