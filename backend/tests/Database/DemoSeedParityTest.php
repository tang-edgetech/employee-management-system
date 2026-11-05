<?php

use App\Domain\People\Employee;
use App\Domain\People\Team;
use App\Domain\Settings\CompanySettings;
use App\Models\User;
use Database\Seeders\DemoCompanySeeder;

/*
| The demo company must match the mockup (brief §6.3, plan 01 §7 "Seed parity").
*/

beforeEach(fn () => $this->seed(DemoCompanySeeder::class));

function emp(string $name): Employee
{
    return Employee::query()->where('full_name', $name)->firstOrFail();
}

it('has the six demo accounts with the right roles', function () {
    $accounts = User::query()->with(['role', 'employee'])->get()
        ->mapWithKeys(fn (User $u) => [$u->email => [$u->role->key, $u->employee?->full_name]])->all();

    expect($accounts)->toBe([
        'tang@hajimi.dev' => ['sysadmin', null],
        'aiman@hajimi.my' => ['exec', 'Aiman Rahman'],
        'nurul@hajimi.my' => ['hr', 'Nurul Huda'],
        'kevin@hajimi.my' => ['lead', 'Kevin Tan'],
        'nadia@hajimi.my' => ['staff', 'Nadia Hassan'],
        'hafiz@hajimi.my' => ['staff', 'Hafiz Ismail'],
    ]);
});

it('has 20 employees and 6 teams', function () {
    expect(Employee::query()->count())->toBe(20)
        ->and(Team::query()->count())->toBe(6);
});

it('has every team lead as a member of their team', function () {
    foreach (Team::query()->with('members')->get() as $team) {
        expect($team->members->pluck('id'))->toContain($team->lead_employee_id);
    }
});

it('has the reporting chain Hafiz -> Nadia -> Kevin -> Grace -> Aiman', function () {
    $chain = [];
    $current = emp('Hafiz Ismail');
    while ($current !== null) {
        $chain[] = $current->full_name;
        $current = $current->superior;
    }

    expect($chain)->toBe(['Hafiz Ismail', 'Nadia Hassan', 'Kevin Tan', 'Grace Lim', 'Aiman Rahman']);
});

it('has Nadia supervising exactly Mei Ling and Hafiz', function () {
    expect(emp('Nadia Hassan')->directReports()->orderBy('full_name')->pluck('full_name')->all())
        ->toBe(['Chow Mei Ling', 'Hafiz Ismail']);
});

it('names the company Hajimi Technologies', function () {
    expect(CompanySettings::current()->company_name)->toBe('Hajimi Technologies Sdn Bhd');
});

it('lets every demo account sign in with the demo password', function () {
    foreach (array_keys(DemoCompanySeeder::ACCOUNTS) as $email) {
        expect(auth()->guard('web')->validate(['email' => $email, 'password' => DemoCompanySeeder::PASSWORD]))->toBeTrue();
    }
});

it('refuses to seed twice or in production', function () {
    expect(fn () => $this->seed(DemoCompanySeeder::class))->toThrow(RuntimeException::class, 'already exist');

    app()->detectEnvironment(fn () => 'production');
    try {
        expect(fn () => (new DemoCompanySeeder)->run())->toThrow(RuntimeException::class, 'never seeded in production');
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});
