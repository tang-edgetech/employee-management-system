<?php

namespace Database\Seeders;

use App\Domain\Access\AccessRole;
use App\Domain\Access\RoleKey;
use App\Domain\People\Employee;
use App\Domain\People\Team;
use App\Domain\People\TeamService;
use App\Domain\Settings\CompanySettings;
use App\Domain\Settings\SettingsService;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The demo company from the mockup ("Hajimi Technologies"): used for sales demos,
 * local development and tests, never for a client (ADR 0002, vision §8).
 * Foundation slice: employees (core columns), reporting lines, teams and the six
 * demo accounts. Later modules extend it (owner decision: the seed grows per module).
 */
class DemoCompanySeeder extends Seeder
{
    /**
     * Password for every demo account. Demo data only, refused in production;
     * meets the password rules.
     */
    public const PASSWORD = 'Demo#Meridian2026';

    /** Mockup id => [employee no, name, status, join date, superior mockup id] (mockup order: superiors first). */
    private const EMPLOYEES = [
        'E001' => ['HJ-0001', 'Aiman Rahman', 'Permanent', '2019-02-01', null],
        'E002' => ['HJ-0002', 'Grace Lim', 'Permanent', '2019-03-11', 'E001'],
        'E003' => ['HJ-0007', 'Priya Nair', 'Permanent', '2020-06-01', 'E001'],
        'E004' => ['HJ-0011', 'Nurul Huda', 'Permanent', '2020-09-14', 'E003'],
        'E015' => ['HJ-0029', 'Lim Jia Hui', 'Permanent', '2022-07-11', 'E004'],
        'E005' => ['HJ-0015', 'Daniel Wong', 'Permanent', '2021-01-04', 'E002'],
        'E008' => ['HJ-0026', 'Arjun Pillai', 'Permanent', '2022-02-07', 'E005'],
        'E018' => ['HJ-0033', 'Wong Kai Jie', 'Permanent', '2023-03-06', 'E005'],
        'E011' => ['HJ-0038', 'Chloe Ong', 'PIP', '2024-01-08', 'E008'],
        'E016' => ['HJ-0048', 'Ahmad Faiz', 'Internship', '2026-09-01', 'E008'],
        'E007' => ['HJ-0023', 'Kevin Tan', 'Permanent', '2021-08-16', 'E002'],
        'E019' => ['HJ-0036', 'Nadia Hassan', 'Permanent', '2023-06-05', 'E007'],
        'E009' => ['HJ-0031', 'Chow Mei Ling', 'Permanent', '2023-04-03', 'E019'],
        'E010' => ['HJ-0044', 'Hafiz Ismail', 'Probation', '2026-06-02', 'E019'],
        'E006' => ['HJ-0019', 'Siti Aisyah', 'Promotion Probation', '2021-05-03', 'E002'],
        'E012' => ['HJ-0035', 'Farah Zain', 'Permanent', '2023-09-18', 'E006'],
        'E013' => ['HJ-0047', 'Ryan Lee', 'Internship', '2026-09-01', 'E012'],
        'E021' => ['HJ-0027', 'Melissa Kho', 'Permanent', '2022-05-09', 'E003'],
        'E014' => ['HJ-0041', 'Jason Teoh', 'Contract', '2025-12-01', 'E021'],
        'E017' => ['HJ-0049', 'Joshua Lau', 'Probation', '2026-08-17', 'E021'],
    ];

    /** Team name => [lead, members] */
    private const TEAMS = [
        'Frontend Squad' => ['E007', ['E007', 'E019', 'E009', 'E010']],
        'Backend Core' => ['E008', ['E008', 'E011', 'E016']],
        'Server & DevOps' => ['E018', ['E018']],
        'Mobile Squad' => ['E006', ['E006', 'E012', 'E013']],
        'WordPress Crew' => ['E021', ['E021', 'E014', 'E017']],
        'People Ops' => ['E004', ['E004', 'E015']],
    ];

    /** email => [name, role, mockup employee id] */
    public const ACCOUNTS = [
        'tang@hajimi.dev' => ['Tang', RoleKey::Sysadmin, null],
        'aiman@hajimi.my' => ['Aiman Rahman', RoleKey::Exec, 'E001'],
        'nurul@hajimi.my' => ['Nurul Huda', RoleKey::Hr, 'E004'],
        'kevin@hajimi.my' => ['Kevin Tan', RoleKey::Lead, 'E007'],
        'nadia@hajimi.my' => ['Nadia Hassan', RoleKey::Staff, 'E019'],
        'hafiz@hajimi.my' => ['Hafiz Ismail', RoleKey::Staff, 'E010'],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The demo company is never seeded in production.');
        }
        if (Employee::query()->exists()) {
            throw new RuntimeException('Employees already exist; the demo company only seeds an empty database.');
        }

        DB::transaction(function () {
            app(SettingsService::class)->update(
                ['company_name' => 'Hajimi Technologies Sdn Bhd'],
                CompanySettings::current()->version,
                null,
            );

            /** @var array<string, Employee> $employees */
            $employees = [];
            foreach (self::EMPLOYEES as $id => [$no, $name, $status, $joined, $superior]) {
                $employee = new Employee([
                    'employee_no' => $no,
                    'full_name' => $name,
                    'work_email' => strtolower(explode(' ', $name)[0]).'@hajimi.my',
                    'status' => $status,
                    'join_date' => $joined,
                ]);
                $employee->forceFill(['superior_id' => $superior === null ? null : $employees[$superior]->id])->save();
                $employees[$id] = $employee;
            }

            $teams = app(TeamService::class);
            foreach (self::TEAMS as $name => [$lead, $members]) {
                $team = Team::query()->create(['name' => $name]);
                foreach ($members as $member) {
                    $teams->addMember($team, $employees[$member]);
                }
                $teams->setLead($team, $employees[$lead]);
            }

            foreach (self::ACCOUNTS as $email => [$name, $role, $employeeId]) {
                $user = new User([
                    'name' => $name,
                    'email' => $email,
                    'password' => self::PASSWORD,
                    'role_id' => AccessRole::idFor($role),
                    'status' => User::STATUS_ACTIVE,
                ]);
                $user->forceFill(['employee_id' => $employeeId === null ? null : $employees[$employeeId]->id])->save();
            }
        });
    }
}
