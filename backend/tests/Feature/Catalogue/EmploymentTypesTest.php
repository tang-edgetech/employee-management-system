<?php

use App\Domain\Catalogue\EmploymentType;
use App\Domain\People\Employee;
use Database\Seeders\DemoCompanySeeder;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => $this->seed(DemoCompanySeeder::class));

function etype(string $name): EmploymentType
{
    return EmploymentType::query()->where('name', $name)->firstOrFail();
}

it('lists the types with conversions and headcount', function () {
    Sanctum::actingAs(demoUser('sinyee'));

    $data = $this->getJson('/api/v1/employment-types')->assertOk()->json('data');

    expect(array_column($data, 'name'))->toBe(['Internship', 'Contract', 'Permanent'])
        ->and(array_column($data[0]['converts_to'], 'name'))->toBe(['Contract', 'Permanent'])
        ->and(array_column($data, 'employees'))->toBe([2, 1, 48]);
});

it('adds a type with its conversions', function () {
    Sanctum::actingAs(demoUser('ellie'));

    $this->postJson('/api/v1/employment-types', [
        'name' => 'Part-time', 'icon' => 'clock', 'default_probation_months' => 1,
        'converts_to' => [etype('Permanent')->public_id],
    ])->assertCreated()->assertJsonPath('data.converts_to.0.name', 'Permanent');

    $this->postJson('/api/v1/employment-types', ['name' => 'part-time'])->assertStatus(422);
    $this->postJson('/api/v1/employment-types', ['name' => 'Gig', 'default_probation_months' => 9])->assertStatus(422);
});

it('renames a type and keeps employees on it', function () {
    Sanctum::actingAs(demoUser('ellie'));
    $contract = etype('Contract');

    $this->patchJson("/api/v1/employment-types/{$contract->public_id}", ['name' => 'Fixed-term contract', 'converts_to' => []], ['If-Match' => '"1"'])
        ->assertOk()->assertJsonPath('data.employees', 1)->assertJsonPath('data.converts_to', []);
});

it('moves employees to another type, then deletes, in one step', function () {
    Sanctum::actingAs(demoUser('ellie'));
    $internship = etype('Internship');

    $this->deleteJson("/api/v1/employment-types/{$internship->public_id}", [], ['If-Match' => '"1"'])
        ->assertStatus(409)->assertJsonPath('code', 'EMPLOYMENT_TYPE_IN_USE');

    $this->deleteJson("/api/v1/employment-types/{$internship->public_id}", ['move_to' => etype('Contract')->public_id], ['If-Match' => '"1"'])
        ->assertOk()->assertJsonPath('data.employees_moved', 2);

    expect(EmploymentType::query()->pluck('name')->all())->toBe(['Contract', 'Permanent'])
        ->and(Employee::query()->where('full_name', 'Syndy')->first()->employmentType->name)->toBe('Contract')
        ->and(DB::table('audit_log')->where('action', 'employment_types.employees_moved')->count())->toBe(1);
});

it('keeps at least one type', function () {
    Sanctum::actingAs(demoUser('ellie'));
    foreach (['Internship', 'Contract'] as $name) {
        $this->deleteJson('/api/v1/employment-types/'.etype($name)->public_id, ['move_to' => etype('Permanent')->public_id], ['If-Match' => '"1"'])->assertOk();
    }

    $this->deleteJson('/api/v1/employment-types/'.etype('Permanent')->public_id, [], ['If-Match' => '"1"'])
        ->assertStatus(409)->assertJsonPath('code', 'LAST_EMPLOYMENT_TYPE');
});

it('refuses changes without org.structure.manage', function () {
    Sanctum::actingAs(demoUser('jeffyeoh'));

    $this->postJson('/api/v1/employment-types', ['name' => 'Part-time'])->assertForbidden();
});
