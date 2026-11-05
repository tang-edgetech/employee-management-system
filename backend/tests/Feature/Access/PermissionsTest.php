<?php

use App\Domain\Access\Permission;
use App\Domain\Access\PermissionMatrix;
use App\Domain\Access\RoleKey;
use Database\Seeders\DemoCompanySeeder;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => $this->seed(DemoCompanySeeder::class));

it('has the same 17 permission keys in code and in the database', function () {
    expect(DB::table('permissions')->orderBy('sort')->pluck('key')->all())->toBe(Permission::values())
        ->and(Permission::values())->toHaveCount(17);
});

it('grants the defaults of brief §5.3', function () {
    $matrix = app(PermissionMatrix::class);

    expect($matrix->permissionsFor(RoleKey::Staff))->toBe(['extension.approve', 'delay.record'])
        ->and($matrix->permissionsFor(RoleKey::Lead))->toBe([
            'org.task_tags.manage', 'people.edit', 'interview.manage', 'project.manage', 'extension.approve', 'delay.record',
        ])
        ->and($matrix->permissionsFor(RoleKey::Hr))->toBe([
            'org.structure.manage', 'org.teams.manage', 'people.edit', 'people.import', 'people.salary.view',
            'people.lifecycle', 'people.data_review.approve', 'candidate.create', 'candidate.view_identity',
            'interview.manage', 'kpi.view_all', 'kpi.configure',
        ])
        ->and($matrix->permissionsFor(RoleKey::Exec))->not->toContain('people.import', 'system.settings')
        ->and($matrix->permissionsFor(RoleKey::Sysadmin))->toBe(Permission::values());
});

it('answers Gate checks from the matrix and denies by default', function () {
    expect(demoUser('nurul')->can('people.salary.view'))->toBeTrue()
        ->and(demoUser('kevin')->can('people.salary.view'))->toBeFalse()
        ->and(demoUser('aiman')->can('system.settings'))->toBeFalse()
        ->and(demoUser('tang')->can('system.settings'))->toBeTrue()
        ->and(demoUser('tang')->can('not.a.permission'))->toBeFalse();
});

it('tells each user their permissions and navigation scope', function (string $account, array $expected) {
    Sanctum::actingAs(demoUser($account));

    $data = $this->getJson('/api/v1/me/permissions')->assertOk()->json('data');

    expect($data['scope'])->toBe($expected)
        ->and($data['permissions'])->toBe(app(PermissionMatrix::class)->permissionsFor(demoUser($account)->roleKey()));
})->with([
    'sysadmin' => ['tang', ['all' => true, 'has_reports' => false, 'leads_teams' => false]],
    'hr' => ['nurul', ['all' => true, 'has_reports' => true, 'leads_teams' => true]],
    'lead' => ['kevin', ['all' => false, 'has_reports' => true, 'leads_teams' => true]],
    'senior' => ['nadia', ['all' => false, 'has_reports' => true, 'leads_teams' => false]],
    'junior' => ['hafiz', ['all' => false, 'has_reports' => false, 'leads_teams' => false]],
]);

it('shows the linked employee on /me, and none for the System Admin', function () {
    Sanctum::actingAs(demoUser('nadia'));
    $this->getJson('/api/v1/me')->assertJsonPath('data.employee.full_name', 'Nadia Hassan')
        ->assertJsonPath('data.employee.employee_no', 'HJ-0036');

    Sanctum::actingAs(demoUser('tang'));
    $this->getJson('/api/v1/me')->assertJsonPath('data.employee', null);
});

it('saves the theme preference', function () {
    Sanctum::actingAs($user = demoUser('hafiz'));

    $this->patchJson('/api/v1/me/preferences', ['ui_layout' => 'kopi'])->assertOk()->assertJsonPath('data.ui_layout', 'kopi');
    $this->patchJson('/api/v1/me/preferences', ['ui_layout' => 'neon'])->assertStatus(422);

    expect($user->fresh()->ui_layout)->toBe('kopi');
});
