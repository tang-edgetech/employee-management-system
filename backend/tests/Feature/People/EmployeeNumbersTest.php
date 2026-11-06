<?php

use App\Domain\People\Employee;
use App\Domain\People\EmployeeNumberFormat;
use App\Domain\People\EmployeeNumbers;
use App\Domain\Settings\CompanySettings;
use Database\Seeders\DemoCompanySeeder;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(DemoCompanySeeder::class);
    $this->version = CompanySettings::current()->version;
});

function newJoiner(string $no = 'TMP'): Employee
{
    $e = new Employee(['employee_no' => $no, 'full_name' => 'New Joiner', 'work_email' => 'new@hajimi.my', 'status' => 'Probation', 'join_date' => '2026-11-02']);
    $e->forceFill(['department_id' => Employee::query()->where('full_name', 'Rex')->value('department_id')]);

    return $e;
}

it('formats numbers from the tokens', function () {
    $f = new EmployeeNumberFormat('{dept}{yy}-{seq}', 5);

    expect($f->format(7, now()->setDate(2026, 1, 1), 'Back End'))->toBe('BE26-00007')
        ->and(EmployeeNumberFormat::departmentCode('Customer Service'))->toBe('CS')
        ->and(EmployeeNumberFormat::problem('HJ-', 4))->toContain('{seq}')
        ->and(EmployeeNumberFormat::problem('HJ-{seq}{x}', 4))->toContain('Unknown token')
        ->and(EmployeeNumberFormat::problem('HJ {seq}', 4))->toContain('letters')
        ->and(EmployeeNumberFormat::problem('COMPANY-{yyyy}-{seq}', 8))->toContain('20 characters')
        ->and(EmployeeNumberFormat::problem('HJ-{seq}', 4))->toBeNull();
});

it('continues the demo numbering at HJ-0052', function () {
    expect(app(EmployeeNumbers::class)->preview())->toBe('HJ-0052');
});

it('issues the next number to a new employee and skips numbers already taken', function () {
    Employee::query()->where('full_name', 'Ronnie')->update(['employee_no' => 'HJ-0052']); // typed in by hand

    $number = DB::transaction(function () {
        $e = newJoiner();
        $e->employee_no = app(EmployeeNumbers::class)->issue($e);
        $e->save();

        return $e->employee_no;
    });

    expect($number)->toBe('HJ-0053')
        ->and(CompanySettings::current()->employee_no_next)->toBe(54);
});

it('changes the format for new employees only through settings', function () {
    Sanctum::actingAs(demoUser('tang'));

    $this->patchJson('/api/v1/settings', ['employee_no_format' => '{dept}-{seq}', 'employee_no_digits' => 5], ['If-Match' => '"'.$this->version.'"'])
        ->assertOk()->assertJsonPath('data.employee_numbers.employee_no_format', '{dept}-{seq}');
    $this->patchJson('/api/v1/settings', ['employee_no_format' => 'HJ-'], ['If-Match' => '"'.($this->version + 1).'"'])
        ->assertStatus(422)->assertJsonValidationErrors('employee_no_format');

    expect(Employee::query()->where('full_name', 'Ah Fa')->value('employee_no'))->toBe('HJ-0001')
        ->and(app(EmployeeNumbers::class)->preview(newJoiner()))->toBe('BE-00052');
});

it('renumbers everyone in join-date order when asked', function () {
    Sanctum::actingAs(demoUser('tang'));

    $this->postJson('/api/v1/employee-numbers/renumber', ['format' => 'MW{yy}-{seq}', 'digits' => 3], ['If-Match' => '"'.$this->version.'"'])
        ->assertOk()->assertJsonPath('data.next_number', 'MW'.now()->format('y').'-052');

    $numbers = Employee::query()->orderBy('join_date')->orderBy('id')->pluck('employee_no')->all();
    expect($numbers)->toHaveCount(51)
        ->and(array_unique($numbers))->toHaveCount(51)
        ->and($numbers[0])->toEndWith('-001')
        ->and(DB::table('audit_log')->where('action', 'employees.renumbered')->count())->toBe(1);

    // A stale settings version is refused.
    $this->postJson('/api/v1/employee-numbers/renumber', ['format' => 'X-{seq}', 'digits' => 4], ['If-Match' => '"'.$this->version.'"'])
        ->assertStatus(412);
});

it('lets only System Admins renumber, and people editors read the next number', function () {
    Sanctum::actingAs(demoUser('ellie'));
    $this->getJson('/api/v1/employee-numbers')->assertOk()->assertJsonPath('data.next_number', 'HJ-0052');
    $this->postJson('/api/v1/employee-numbers/renumber', ['format' => 'X-{seq}', 'digits' => 4], ['If-Match' => '"'.$this->version.'"'])
        ->assertForbidden();

    Sanctum::actingAs(demoUser('sinyee'));
    $this->getJson('/api/v1/employee-numbers')->assertForbidden();
});
