<?php

use App\Domain\Catalogue\Designation;
use App\Domain\People\Employee;
use App\Domain\People\Team;
use App\Domain\People\TeamService;
use App\Exceptions\ApiProblem;
use Database\Seeders\DemoCompanySeeder;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => $this->seed(DemoCompanySeeder::class));

function ladder(): array
{
    return Designation::query()->orderBy('level')->pluck('name')->all();
}

function ladderEtag($test): string
{
    return $test->getJson('/api/v1/designations')->headers->get('ETag');
}

it('lists the ladder lowest first to everyone, with salary bands only for salary viewers', function () {
    Sanctum::actingAs(demoUser('sinyee'));
    $staff = $this->getJson('/api/v1/designations')->assertOk()->assertHeader('ETag');
    expect(array_column($staff->json('data'), 'name'))->toBe(ladder())
        ->and($staff->json('data.0'))->not->toHaveKey('salary_min')
        ->and($staff->json('data.0.can_lead_team'))->toBeFalse();

    Sanctum::actingAs(demoUser('ellie'));
    expect($this->getJson('/api/v1/designations')->json('data.0.salary_min'))->toBe('1000.00');
});

it('adds a designation on the top rung', function () {
    Sanctum::actingAs(demoUser('ellie'));

    $this->postJson('/api/v1/designations', ['name' => 'Principal', 'track' => 'Executive'])
        ->assertCreated()->assertJsonPath('data.level', 9);

    expect(ladder()[9])->toBe('Principal');
});

it('refuses ladder changes without org.structure.manage', function () {
    Sanctum::actingAs(demoUser('sinyee'));

    $this->postJson('/api/v1/designations', ['name' => 'Principal', 'track' => 'Executive'])->assertForbidden();
    $this->putJson('/api/v1/designations/order', ['order' => []], ['If-Match' => '"1"'])->assertForbidden();
});

it('reorders the whole ladder and records it in the audit log', function () {
    Sanctum::actingAs(demoUser('ellie'));
    $ids = Designation::query()->orderBy('level')->pluck('public_id')->all();
    [$ids[2], $ids[3]] = [$ids[3], $ids[2]]; // Senior 1 below Junior 2

    $this->putJson('/api/v1/designations/order', ['order' => $ids], ['If-Match' => ladderEtag($this)])->assertOk();

    expect(array_slice(ladder(), 1, 3))->toBe(['Junior 1', 'Senior 1', 'Junior 2'])
        ->and(Designation::query()->orderBy('level')->pluck('level')->all())->toBe(range(0, 8))
        ->and(DB::table('audit_log')->where('action', 'designations.reordered')->count())->toBe(1);
});

it('refuses a reorder based on a stale ladder or a partial list', function () {
    Sanctum::actingAs(demoUser('ellie'));
    $etag = ladderEtag($this);
    $ids = Designation::query()->orderBy('level')->pluck('public_id')->all();

    $this->putJson('/api/v1/designations/order', ['order' => array_slice($ids, 1)], ['If-Match' => $etag])
        ->assertStatus(422)->assertJsonPath('code', 'LADDER_INCOMPLETE');

    // Someone else adds a rung after this client loaded the ladder.
    $this->postJson('/api/v1/designations', ['name' => 'Principal', 'track' => 'Executive'])->assertCreated();
    $this->putJson('/api/v1/designations/order', ['order' => array_reverse($ids)], ['If-Match' => $etag])->assertStatus(412);

    expect(ladder()[0])->toBe('Intern');
});

it('edits a designation with its version, and keeps the band valid', function () {
    Sanctum::actingAs(demoUser('ellie'));
    $senior = Designation::query()->where('name', 'Senior 1')->firstOrFail();

    $this->patchJson("/api/v1/designations/{$senior->public_id}", ['salary_max' => 100], ['If-Match' => '"1"'])
        ->assertStatus(422)->assertJsonValidationErrors('salary_max');
    $this->patchJson("/api/v1/designations/{$senior->public_id}", ['name' => 'Senior I'], ['If-Match' => '"1"'])
        ->assertOk()->assertJsonPath('data.name', 'Senior I')->assertHeader('ETag', '"2"');
    $this->patchJson("/api/v1/designations/{$senior->public_id}", ['name' => 'Senior One'], ['If-Match' => '"1"'])
        ->assertStatus(412);
});

it('deletes only an unused designation and closes the gap', function () {
    Sanctum::actingAs(demoUser('ellie'));
    $manager = Designation::query()->where('name', 'Manager')->firstOrFail(); // nobody holds it
    $senior = Designation::query()->where('name', 'Senior 1')->firstOrFail();

    $this->deleteJson("/api/v1/designations/{$senior->public_id}", [], ['If-Match' => '"1"'])
        ->assertStatus(409)->assertJsonPath('code', 'DESIGNATION_IN_USE');
    $this->deleteJson("/api/v1/designations/{$manager->public_id}", [], ['If-Match' => '"1"'])->assertNoContent();

    expect(ladder())->not->toContain('Manager')
        ->and(Designation::query()->orderBy('level')->pluck('level')->all())->toBe(range(0, 7));
});

it('lets only designations that can lead become team leads (D17)', function () {
    $mobile = Team::query()->where('name', 'Mobile')->firstOrFail();
    $sinYee = Employee::query()->where('full_name', 'Sin Yee')->firstOrFail(); // Junior 1
    app(TeamService::class)->addMember($mobile, $sinYee);

    expect(fn () => app(TeamService::class)->addLead($mobile, $sinYee))
        ->toThrow(ApiProblem::class, "Sin Yee is Junior 1: this designation can't lead a group.");

    // The company decides which designations may lead.
    Designation::query()->where('name', 'Junior 1')->update(['can_lead_team' => true]);
    app(TeamService::class)->addLead($mobile, $sinYee);

    expect($mobile->leads()->pluck('full_name')->all())->toContain('Sin Yee');
});
