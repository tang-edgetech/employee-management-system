<?php

use App\Domain\Access\ScopeResolver;
use App\Domain\People\Employee;
use App\Domain\People\EmployeeHierarchy;
use App\Domain\People\Team;
use App\Domain\People\TeamService;
use App\Exceptions\ApiProblem;
use Database\Seeders\DemoCompanySeeder;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => $this->seed(DemoCompanySeeder::class));

/** Names of the employees a demo account can see, sorted. */
function visibleTo(string $account): array
{
    $scope = app(ScopeResolver::class)->for(demoUser($account));

    return $scope->apply(Employee::query())->orderBy('full_name')->pluck('full_name')->all();
}

function person(string $name): Employee
{
    return Employee::query()->where('full_name', $name)->firstOrFail();
}

it('lets the System Admin, C-level and HR see everyone', function (string $account) {
    expect(visibleTo($account))->toHaveCount(20)
        ->and(app(ScopeResolver::class)->for(demoUser($account))->all)->toBeTrue();
})->with(['tang', 'aiman', 'nurul']);

it('lets a junior with no reports see only themselves', function () {
    expect(visibleTo('hafiz'))->toBe(['Hafiz Ismail']);
});

it('lets a senior who supervises juniors see them', function () {
    expect(visibleTo('nadia'))->toBe(['Chow Mei Ling', 'Hafiz Ismail', 'Nadia Hassan']);
});

it('lets a lead see their team members and everyone below those members', function () {
    expect(visibleTo('kevin'))->toBe(['Chow Mei Ling', 'Hafiz Ismail', 'Kevin Tan', 'Nadia Hassan']);
});

it('includes reports of a lead\'s team members even when they are not in the team', function () {
    // Kevin's squad gets Daniel, whose reports (Arjun, Wong Kai Jie, and below) are in other teams.
    app(TeamService::class)->addMember(Team::query()->where('name', 'Frontend Squad')->firstOrFail(), person('Daniel Wong'));
    app(ScopeResolver::class)->forget();

    expect(visibleTo('kevin'))->toContain('Daniel Wong', 'Arjun Pillai', 'Wong Kai Jie', 'Chloe Ong', 'Ahmad Faiz')
        ->not->toContain('Grace Lim', 'Siti Aisyah');
});

it('reports whether the user has reports or leads a team, for the navigation', function () {
    $nadia = app(ScopeResolver::class)->for(demoUser('nadia'));
    $hafiz = app(ScopeResolver::class)->for(demoUser('hafiz'));
    $kevin = app(ScopeResolver::class)->for(demoUser('kevin'));

    expect([$nadia->hasReports, $nadia->leadsTeams])->toBe([true, false])
        ->and([$hafiz->hasReports, $hafiz->leadsTeams])->toBe([false, false])
        ->and([$kevin->hasReports, $kevin->leadsTeams])->toBe([true, true]);
});

it('leaves out employees who were deleted', function () {
    person('Hafiz Ismail')->delete();
    app(ScopeResolver::class)->forget();

    expect(visibleTo('nadia'))->toBe(['Chow Mei Ling', 'Nadia Hassan']);
});

describe('reporting lines', function () {
    it('refuses a superior that would create a loop, with a clear error', function () {
        expect(fn () => app(EmployeeHierarchy::class)->setSuperior(person('Kevin Tan'), person('Hafiz Ismail')))
            ->toThrow(ApiProblem::class, 'report to themselves');

        expect(person('Kevin Tan')->superior?->full_name)->toBe('Grace Lim');
    });

    it('moves someone, bumps the version and audits it', function () {
        $hafiz = person('Hafiz Ismail');
        app(EmployeeHierarchy::class)->setSuperior($hafiz, person('Kevin Tan'));

        expect($hafiz->fresh()->superior->full_name)->toBe('Kevin Tan')
            ->and($hafiz->fresh()->version)->toBe(2)
            ->and(DB::table('audit_log')->where('action', 'employees.superior_changed')->count())->toBe(1);
    });
});

describe('teams', function () {
    it('adds a new lead to the team automatically', function () {
        $team = Team::query()->where('name', 'Server & DevOps')->firstOrFail();
        app(TeamService::class)->setLead($team, person('Daniel Wong'));

        expect($team->members()->pluck('full_name'))->toContain('Daniel Wong');
    });

    it('refuses to remove the lead from their own team', function () {
        $team = Team::query()->where('name', 'Frontend Squad')->firstOrFail();

        expect(fn () => app(TeamService::class)->removeMember($team, person('Kevin Tan')))
            ->toThrow(ApiProblem::class, 'new lead');
    });
});
