<?php

use App\Domain\Access\AccessRole;
use App\Domain\Access\PermissionMatrix;
use App\Domain\Access\RoleKey;
use Database\Seeders\DemoCompanySeeder;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => $this->seed(DemoCompanySeeder::class));

function roleVersion(string $key): string
{
    return '"'.AccessRole::query()->where('key', $key)->value('version').'"';
}

function putPermissions($test, string $role, array $permissions, ?string $etag = null)
{
    return $test->putJson("/api/v1/access-roles/{$role}/permissions", ['permissions' => $permissions], ['If-Match' => $etag ?? roleVersion($role)]);
}

it('is only for users with Global Settings access', function (string $account, int $status) {
    Sanctum::actingAs(demoUser($account));

    $this->getJson('/api/v1/access-roles')->assertStatus($status);
    putPermissions($this, 'staff', ['delay.record'])->assertStatus($status === 200 ? 200 : 403);
})->with([
    'sysadmin' => ['tang', 200],
    'exec' => ['aiman', 403],
    'hr' => ['nurul', 403],
    'staff' => ['hafiz', 403],
]);

it('lists the roles with user counts and the catalogue', function () {
    Sanctum::actingAs(demoUser('tang'));

    $data = $this->getJson('/api/v1/access-roles')->assertOk()->json('data');

    expect(collect($data['roles'])->pluck('users', 'key')->all())->toBe(['sysadmin' => 1, 'exec' => 1, 'hr' => 1, 'lead' => 1, 'staff' => 2])
        ->and(collect($data['roles'])->firstWhere('key', 'sysadmin')['locked'])->toBeTrue()
        ->and($data['permissions'])->toHaveCount(17)
        ->and($data['permissions'][0])->toBe(['key' => 'org.structure.manage', 'group' => 'Organization', 'label' => 'Manage departments, designations & employment types']);
});

it('changes a role\'s permissions, audits it and takes effect on the next request', function () {
    Sanctum::actingAs(demoUser('tang'));

    putPermissions($this, 'staff', ['delay.record', 'kpi.view_all'])
        ->assertOk()
        ->assertJsonPath('data.permissions', ['delay.record', 'kpi.view_all']);

    $audit = DB::table('audit_log')->where('action', 'access_roles.permissions_changed')->first();
    expect(json_decode($audit->changes, true))->toMatchArray([
        'added' => ['kpi.view_all'],
        'removed' => ['extension.approve'],
    ]);

    app()->forgetScopedInstances();
    expect(demoUser('hafiz')->can('kpi.view_all'))->toBeTrue()
        ->and(demoUser('hafiz')->can('extension.approve'))->toBeFalse();
});

it('refuses to edit the locked System Admin role', function () {
    Sanctum::actingAs(demoUser('tang'));

    putPermissions($this, 'sysadmin', [])->assertStatus(409)->assertJsonPath('code', 'ROLE_LOCKED');
});

it('stops someone removing Global Settings access from their own role', function () {
    // Give C-level settings access, then sign in as C-level and try to take it away.
    app(PermissionMatrix::class)->setRolePermissions(
        AccessRole::query()->where('key', 'exec')->firstOrFail(),
        [...app(PermissionMatrix::class)->permissionsFor(RoleKey::Exec), 'system.settings'],
        1,
        demoUser('tang'),
    );
    Sanctum::actingAs(demoUser('aiman'));

    putPermissions($this, 'exec', ['people.edit'])->assertStatus(409)->assertJsonPath('code', 'CANNOT_REMOVE_OWN_ACCESS');
    putPermissions($this, 'hr', ['people.edit'])->assertOk();
});

it('needs If-Match and refuses a stale version', function () {
    Sanctum::actingAs(demoUser('tang'));

    $this->putJson('/api/v1/access-roles/staff/permissions', ['permissions' => []])->assertStatus(428);
    putPermissions($this, 'staff', ['delay.record'])->assertOk();
    putPermissions($this, 'staff', [], '"1"')->assertStatus(412);
});

it('rejects unknown permissions and unknown roles', function () {
    Sanctum::actingAs(demoUser('tang'));

    putPermissions($this, 'staff', ['people.fire'])->assertStatus(422)->assertJsonPath('code', 'UNKNOWN_PERMISSION');
    $this->putJson('/api/v1/access-roles/intern/permissions', ['permissions' => []], ['If-Match' => '"1"'])->assertNotFound();
});

it('does nothing and keeps the version when nothing changes', function () {
    Sanctum::actingAs(demoUser('tang'));

    putPermissions($this, 'staff', ['extension.approve', 'delay.record'])->assertOk()->assertJsonPath('data.version', 1);

    expect(DB::table('audit_log')->where('action', 'access_roles.permissions_changed')->count())->toBe(0);
});
