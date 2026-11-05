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
    expect(demoUser('ellie')->can('people.salary.view'))->toBeTrue()
        ->and(demoUser('teechienyap')->can('people.salary.view'))->toBeFalse()
        ->and(demoUser('ahfa')->can('system.settings'))->toBeFalse()
        ->and(demoUser('tang')->can('system.settings'))->toBeTrue()
        ->and(demoUser('tang')->can('not.a.permission'))->toBeFalse();
});

it('tells each user their permissions and navigation scope', function (string $account, array $expected) {
    Sanctum::actingAs(demoUser($account));

    $data = $this->getJson('/api/v1/me/permissions')->assertOk()->json('data');

    expect($data['scope'])->toBe($expected)
        ->and($data['permissions'])->toBe(app(PermissionMatrix::class)->permissionsFor(demoUser($account)->roleKey()));
})->with([
    'sysadmin' => ['tang', ['all' => true, 'has_reports' => false, 'leads_teams' => false, 'heads_department' => false]],
    'hr (leads the Admin group)' => ['ellie', ['all' => true, 'has_reports' => false, 'leads_teams' => true, 'heads_department' => false]],
    'head of department' => ['teechienyap', ['all' => false, 'has_reports' => true, 'leads_teams' => false, 'heads_department' => true]],
    'team lead with reports' => ['jeffyeoh', ['all' => false, 'has_reports' => true, 'leads_teams' => true, 'heads_department' => false]],
    'junior' => ['sinyee', ['all' => false, 'has_reports' => false, 'leads_teams' => false, 'heads_department' => false]],
]);

it('shows the linked employee and department on /me, and none for the System Admin', function () {
    Sanctum::actingAs(demoUser('jeffyeoh'));
    $this->getJson('/api/v1/me')->assertJsonPath('data.employee.full_name', 'Jeff Yeoh')
        ->assertJsonPath('data.employee.employee_no', 'HJ-0030')
        ->assertJsonPath('data.employee.department', 'Front End');

    Sanctum::actingAs(demoUser('tang'));
    $this->getJson('/api/v1/me')->assertJsonPath('data.employee', null);
});

it('saves the language preference', function () {
    Sanctum::actingAs($user = demoUser('sinyee'));

    $this->patchJson('/api/v1/me/preferences', ['locale' => 'zh-CN'])->assertOk()->assertJsonPath('data.locale', 'zh-CN');
    $this->patchJson('/api/v1/me/preferences', ['locale' => 'fr'])->assertStatus(422);

    expect($user->fresh()->locale)->toBe('zh-CN');
});
