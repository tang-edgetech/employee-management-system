<?php

use App\Domain\People\Department;
use App\Domain\People\Employee;
use App\Domain\People\Team;
use App\Domain\Settings\CompanySettings;
use App\Models\User;
use Database\Seeders\DemoCompanySeeder;

/*
| The demo company must match the HTML mockup (plan 01 §9.2, D14).
*/

beforeEach(fn () => $this->seed(DemoCompanySeeder::class));

function emp(string $name): Employee
{
    return Employee::query()->where('full_name', $name)->firstOrFail();
}

function chainOf(string $name): array
{
    $chain = [];
    for ($e = emp($name); $e !== null; $e = $e->superior) {
        $chain[] = $e->full_name;
    }

    return $chain;
}

it('has the six demo accounts with the right roles', function () {
    $accounts = User::query()->with(['role', 'employee'])->get()
        ->mapWithKeys(fn (User $u) => [$u->email => [$u->role->key, $u->employee?->full_name]])->all();

    expect($accounts)->toBe([
        'tang@hajimi.dev' => ['sysadmin', null],
        'ahfa@hajimi.my' => ['exec', 'Ah Fa'],
        'ellie@hajimi.my' => ['hr', 'Ellie'],
        'teechienyap@hajimi.my' => ['lead', 'Tee Chien Yap'],
        'jeffyeoh@hajimi.my' => ['staff', 'Jeff Yeoh'],
        'sinyee@hajimi.my' => ['staff', 'Sin Yee'],
    ]);
});

it('has 51 people in Management plus 9 departments, with the chart\'s head counts', function () {
    $counts = Department::query()->orderBy('sort')->withCount('employees')->pluck('employees_count', 'name')->all();

    expect(Employee::query()->count())->toBe(51)
        ->and($counts)->toBe([
            'Management' => 2, 'General' => 5, 'System' => 2, 'Back End' => 13, 'WordPress' => 6,
            'Front End' => 7, 'Mobile' => 4, 'Product Team' => 2, 'Admin' => 1, 'Customer Service' => 9,
        ]);
});

it('has the heads of department from the chart, and none where the chart shows none', function () {
    $heads = Department::query()->orderBy('sort')->with('head')->get()->mapWithKeys(fn (Department $d) => [$d->name => $d->head?->full_name])->all();

    expect($heads)->toBe([
        'Management' => 'Ah Fa', 'General' => null, 'System' => null, 'Back End' => 'Gan Siong Yi', 'WordPress' => 'Wong Wei Yee',
        'Front End' => 'Tee Chien Yap', 'Mobile' => null, 'Product Team' => null, 'Admin' => null, 'Customer Service' => 'Angie',
    ]);
});

it('has groups with zero, one or several team leads, each a member', function () {
    $leads = Team::query()->with(['leads', 'members'])->get();

    foreach ($leads as $team) {
        expect($team->members->pluck('id')->all())->toContain(...$team->leads->pluck('id')->all());
    }
    expect($leads->firstWhere('name', 'Back End')->leads->pluck('full_name')->sort()->values()->all())->toBe(['Chris', 'Rex'])
        ->and($leads->firstWhere('name', 'Customer Service')->leads)->toHaveCount(0)
        ->and($leads->firstWhere('name', 'Business Analyst')->members)->toHaveCount(0);
});

it('has per-person reporting lines up to the CEO', function () {
    expect(chainOf('Sin Yee'))->toBe(['Sin Yee', 'Jeff Yeoh', 'Tee Chien Yap', 'Leon Ong', 'Ah Fa'])
        ->and(chainOf('Adam Lai'))->toBe(['Adam Lai', 'Chris', 'Rex', 'Gan Siong Yi', 'Leon Ong', 'Ah Fa'])
        ->and(chainOf('Ronnie'))->toBe(['Ronnie', 'Angie', 'Leon Ong', 'Ah Fa']);
});

it('has Jeff Yeoh supervising his five group members', function () {
    expect(emp('Jeff Yeoh')->directReports()->orderBy('full_name')->pluck('full_name')->all())
        ->toBe(['AK', 'Ivan', 'KY', 'Sin Yee', 'Syndy']);
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
