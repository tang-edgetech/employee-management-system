<?php

use Database\Seeders\DemoCompanySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
| Profile: display name, own picture and version (plan 01 §9.2).
*/

beforeEach(function () {
    Storage::fake('uploads');
    $this->seed(DemoCompanySeeder::class);
});

/** Uploads and completes an avatar for a demo account; returns its file id. */
function uploadAvatar($test, string $account): string
{
    $img = imagecreatetruecolor(300, 300);
    ob_start();
    imagepng($img);
    $bytes = (string) ob_get_clean();

    Sanctum::actingAs(demoUser($account));
    $presign = $test->postJson('/api/v1/files/presign', ['purpose' => 'avatar', 'filename' => 'me.png', 'size' => strlen($bytes), 'mime' => 'image/png'])->assertCreated();
    $test->call('PUT', $presign->json('data.upload.url'), [], [], [], ['CONTENT_TYPE' => 'image/png'], $bytes)->assertNoContent();
    $id = $presign->json('data.file.id');
    $test->postJson("/api/v1/files/{$id}/complete")->assertOk();

    return $id;
}

it('changes the display name, trims it and audits it; the legal name stays', function () {
    Sanctum::actingAs($user = demoUser('jeffyeoh'));

    $this->patchJson('/api/v1/me', ['display_name' => '  Jeff  '])->assertOk()
        ->assertJsonPath('data.display_name', 'Jeff')
        ->assertJsonPath('data.employee.full_name', 'Jeff Yeoh');

    $this->patchJson('/api/v1/me', ['display_name' => ''])->assertStatus(422);
    $this->patchJson('/api/v1/me', ['display_name' => str_repeat('x', 61)])->assertStatus(422);

    expect($user->fresh()->display_name)->toBe('Jeff')
        ->and(DB::table('audit_log')->where('action', 'user.profile_changed')->count())->toBe(1);
});

it('does not audit when nothing changes', function () {
    Sanctum::actingAs(demoUser('jeffyeoh'));
    $this->patchJson('/api/v1/me', ['display_name' => 'Jeff'])->assertOk();
    $this->patchJson('/api/v1/me', ['display_name' => 'Jeff'])->assertOk();

    expect(DB::table('audit_log')->where('action', 'user.profile_changed')->count())->toBe(1);
});

it('sets a personal picture that only its owner can open', function () {
    $id = uploadAvatar($this, 'sinyee');

    $this->patchJson('/api/v1/me', ['avatar_file_id' => $id])->assertOk()->assertJsonPath('data.avatar_file_id', $id);
    $this->getJson("/api/v1/files/{$id}/url")->assertOk();

    // Not even HR or the System Admin: others see the first letter of the name.
    foreach (['ellie', 'tang', 'jeffyeoh'] as $other) {
        Sanctum::actingAs(demoUser($other));
        $this->getJson("/api/v1/files/{$id}/url")->assertStatus(403);
    }
});

it('refuses someone else\'s file or a file of another purpose as the picture', function () {
    $theirs = uploadAvatar($this, 'jeffyeoh');

    Sanctum::actingAs(demoUser('sinyee'));
    $this->patchJson('/api/v1/me', ['avatar_file_id' => $theirs])->assertStatus(422)->assertJsonPath('code', 'INVALID_AVATAR');
    $this->patchJson('/api/v1/me', ['avatar_file_id' => 'file_nope0000'])->assertStatus(422);
});

it('removes the picture', function () {
    $id = uploadAvatar($this, 'sinyee');
    $this->patchJson('/api/v1/me', ['avatar_file_id' => $id])->assertOk();

    $this->patchJson('/api/v1/me', ['avatar_file_id' => null])->assertOk()->assertJsonPath('data.avatar_file_id', null);
});

it('shows the running version to signed-in users only', function () {
    config(['meridian.version' => ['number' => '0.6.0', 'build' => '42', 'commit' => 'abc1234']]);

    $this->getJson('/api/v1/version')->assertStatus(401);

    Sanctum::actingAs(demoUser('sinyee'));
    $this->getJson('/api/v1/version')->assertOk()
        ->assertJsonPath('data.version', '0.6.0')
        ->assertJsonPath('data.build', '42')
        ->assertJsonPath('data.commit', 'abc1234');
});
