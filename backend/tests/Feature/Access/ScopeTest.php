<?php

use App\Domain\Access\ScopeResolver;
use App\Domain\People\Employee;
use App\Domain\People\EmployeeHierarchy;
use App\Domain\People\Team;
use App\Domain\People\TeamService;
use App\Exceptions\ApiProblem;
use Database\Seeders\DemoCompanySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => $this->seed(DemoCompanySeeder::class));

/** Names of the employees a demo account can see, sorted. */
function visibleTo(string $account): array
{
    app(ScopeResolver::class)->forget();
    $scope = app(ScopeResolver::class)->for(demoUser($account));

    return $scope->apply(Employee::query())->orderBy('full_name')->pluck('full_name')->all();
}

function person(string $name): Employee
{
    return Employee::query()->where('full_name', $name)->firstOrFail();
}

function group(string $name): Team
{
    return Team::query()->where('name', $name)->firstOrFail();
}

it('lets the System Admin, C-level and HR see everyone', function (string $account) {
    expect(visibleTo($account))->toHaveCount(51)
        ->and(app(ScopeResolver::class)->for(demoUser($account))->all)->toBeTrue();
})->with(['tang', 'ahfa', 'ellie']);

it('lets a junior with no reports see only themselves', function () {
    expect(visibleTo('sinyee'))->toBe(['Sin Yee']);
});

it('lets someone see the people who report to them', function () {
    expect(visibleTo('jeffyeoh'))->toBe(['AK', 'Ivan', 'Jeff Yeoh', 'KY', 'Sin Yee', 'Syndy']);
});

it('lets a head of department see the whole department', function () {
    expect(visibleTo('teechienyap'))->toBe(['AK', 'Ivan', 'Jeff Yeoh', 'KY', 'Sin Yee', 'Syndy', 'Tee Chien Yap'])
        ->and(app(ScopeResolver::class)->for(demoUser('teechienyap'))->headsDepartment)->toBeTrue();
});

it('lets a head of department see newcomers to the department even before their reporting line is set', function () {
    $new = new Employee(['employee_no' => 'HJ-0999', 'full_name' => 'New Joiner', 'work_email' => 'new@hajimi.my', 'status' => 'Probation', 'join_date' => '2026-11-01']);
    $new->forceFill(['department_id' => person('Tee Chien Yap')->department_id])->save();

    expect(visibleTo('teechienyap'))->toContain('New Joiner')
        ->and(visibleTo('jeffyeoh'))->not->toContain('New Joiner');
});

it('lets a team lead see their group members and everyone below them, whatever their role', function () {
    // Sin Yee (staff) becomes a team lead of the Mobile group.
    $mobile = group('Mobile');
    app(TeamService::class)->addMember($mobile, person('Sin Yee'));
    app(TeamService::class)->addLead($mobile, person('Sin Yee'));

    expect(visibleTo('sinyee'))->toBe(['Jonathan', 'Mei Yi', 'Nicholas', 'Sin Yee', 'Szen Yew']);
});

it('leaves out employees who were deleted', function () {
    person('Sin Yee')->delete();

    expect(visibleTo('jeffyeoh'))->not->toContain('Sin Yee');
});

it('reports flags for the navigation', function () {
    $tee = app(ScopeResolver::class)->for(demoUser('teechienyap'));
    $jeff = app(ScopeResolver::class)->for(demoUser('jeffyeoh'));
    $sin = app(ScopeResolver::class)->for(demoUser('sinyee'));

    expect([$tee->hasReports, $tee->leadsTeams, $tee->headsDepartment])->toBe([true, false, true])
        ->and([$jeff->hasReports, $jeff->leadsTeams, $jeff->headsDepartment])->toBe([true, true, false])
        ->and([$sin->hasReports, $sin->leadsTeams, $sin->headsDepartment])->toBe([false, false, false]);
});

describe('reporting lines (set per person)', function () {
    it('refuses a superior that would create a loop, with a clear error', function () {
        expect(fn () => app(EmployeeHierarchy::class)->setSuperior(person('Tee Chien Yap'), person('Sin Yee')))
            ->toThrow(ApiProblem::class, 'report to themselves');

        expect(person('Tee Chien Yap')->superior?->full_name)->toBe('Leon Ong');
    });

    it('lets anyone report to any colleague, not just a team lead, and audits it', function () {
        $sin = person('Sin Yee');
        app(EmployeeHierarchy::class)->setSuperior($sin, person('Ivan'));

        expect($sin->fresh()->superior->full_name)->toBe('Ivan')
            ->and(visibleTo('sinyee'))->toBe(['Sin Yee'])
            ->and(DB::table('audit_log')->where('action', 'employees.superior_changed')->count())->toBe(1);
    });
});

describe('team leads (zero, one or several)', function () {
    it('allows several team leads in one group', function () {
        app(TeamService::class)->addLead(group('Back End'), person('Chris'));

        expect(group('Back End')->leads()->pluck('full_name')->sort()->values()->all())->toBe(['Chris', 'Kai Seng (KS)', 'Rex']);
    });

    it('allows a group with no team lead', function () {
        app(TeamService::class)->removeLead(group('Team 1'), person('Wilson Chia'));

        expect(group('Team 1')->leads()->count())->toBe(0)
            ->and(group('Team 1')->members()->count())->toBe(2);
    });

    it('requires a team lead to be a member', function () {
        expect(fn () => app(TeamService::class)->addLead(group('Mobile'), person('Ivan')))
            ->toThrow(ApiProblem::class, 'Add this person to the group');

        // The database refuses it too.
        expect(fn () => DB::table('team_leads')->insert(['team_id' => group('Mobile')->id, 'employee_id' => person('Ivan')->id]))
            ->toThrow(QueryException::class);
    });

    it('drops the team-lead role when someone leaves the group', function () {
        app(TeamService::class)->removeMember(group('Back End'), person('Kai Seng (KS)'));

        expect(group('Back End')->leads()->pluck('full_name')->all())->toBe(['Rex']);
    });
});
