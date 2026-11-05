<?php

use Database\Seeders\DemoCompanySeeder;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(DemoCompanySeeder::class);
    $this->version = DB::table('company_settings')->value('version');
});

it('requires sign-in', function () {
    $this->getJson('/api/v1/settings')->assertStatus(401);
});

it('shows each reader only the sections they may see, with an ETag', function (string $account, array $sections) {
    Sanctum::actingAs(demoUser($account));

    $response = $this->getJson('/api/v1/settings')->assertOk();

    expect(array_keys($response->json('data')))->toBe($sections);
    $response->assertHeader('ETag', '"'.$this->version.'"')->assertJsonPath('meta.version', $this->version);
})->with([
    'staff' => ['sinyee', ['company', 'work']],
    'lead' => ['teechienyap', ['company', 'work']],
    'hr' => ['ellie', ['company', 'work', 'employment', 'recruitment', 'kpi']],
    'exec (no candidate.create by default)' => ['ahfa', ['company', 'work', 'employment', 'kpi']],
    'sysadmin' => ['tang', ['company', 'work', 'security', 'employment', 'recruitment', 'kpi']],
]);

it('lets the System Admin change settings with the current version', function () {
    Sanctum::actingAs(demoUser('tang'));

    $this->patchJson('/api/v1/settings', ['session_timeout_minutes' => 60], ['If-Match' => '"'.$this->version.'"'])
        ->assertOk()
        ->assertJsonPath('data.security.session_timeout_minutes', 60)
        ->assertHeader('ETag', '"'.($this->version + 1).'"');

    expect(DB::table('audit_log')->where('action', 'company_settings.updated')->latest('id')->value('actor_user_id'))
        ->toBe(demoUser('tang')->id);
});

it('needs If-Match, and refuses a stale one', function () {
    Sanctum::actingAs(demoUser('tang'));

    $this->patchJson('/api/v1/settings', ['session_timeout_minutes' => 60])->assertStatus(428);
    $this->patchJson('/api/v1/settings', ['session_timeout_minutes' => 60], ['If-Match' => '"'.($this->version - 1).'"'])->assertStatus(412);
});

it('lets HR change KPI weights but not security settings', function () {
    Sanctum::actingAs(demoUser('ellie'));
    $etag = ['If-Match' => '"'.$this->version.'"'];

    $this->patchJson('/api/v1/settings', ['two_fa_enabled' => true], $etag)
        ->assertStatus(403)->assertJsonPath('detail', 'Changing these settings needs: system.settings.');

    $this->patchJson('/api/v1/settings', ['kpi_mode' => 'external'], $etag)->assertOk()->assertJsonPath('data.kpi.kpi_mode', 'external');
});

it('refuses a mixed change if any field is not allowed, and changes nothing', function () {
    Sanctum::actingAs(demoUser('ellie'));

    $this->patchJson('/api/v1/settings', ['kpi_mode' => 'external', 'company_name' => 'X'], ['If-Match' => '"'.$this->version.'"'])
        ->assertStatus(403);

    expect(DB::table('company_settings')->value('kpi_mode'))->toBe('system');
});

it('refuses staff changing anything', function () {
    Sanctum::actingAs(demoUser('sinyee'));

    $this->patchJson('/api/v1/settings', ['checkin_every_workdays' => 3], ['If-Match' => '"'.$this->version.'"'])->assertStatus(403);
});

it('rejects unknown fields and empty updates', function () {
    Sanctum::actingAs(demoUser('tang'));
    $etag = ['If-Match' => '"'.$this->version.'"'];

    $this->patchJson('/api/v1/settings', ['version' => 99], $etag)->assertStatus(422)->assertJsonPath('code', 'UNKNOWN_FIELD');
    $this->patchJson('/api/v1/settings', [], $etag)->assertStatus(422)->assertJsonPath('code', 'NOTHING_TO_UPDATE');
});

it('reports which sections the reader may edit', function () {
    Sanctum::actingAs(demoUser('ellie'));
    $this->getJson('/api/v1/settings')->assertJsonPath('meta.can_edit', ['kpi.configure']);

    Sanctum::actingAs(demoUser('tang'));
    $this->getJson('/api/v1/settings')->assertJsonPath('meta.can_edit', ['system.settings', 'kpi.configure']);
});
