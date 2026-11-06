<?php

namespace Database\Seeders;

use App\Domain\Access\AccessRole;
use App\Domain\Access\RoleKey;
use App\Domain\Catalogue\Designation;
use App\Domain\Catalogue\EmploymentType;
use App\Domain\Catalogue\EmploymentTypeService;
use App\Domain\Catalogue\TaskTagService;
use App\Domain\People\Department;
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
 * The demo company, matching the HTML mockup (plan 01 §9.2, D14): CEO, GM, 9
 * departments, 51 people, groups with zero or more team leads, per-person reporting
 * lines, the job catalogues (designations, employment types, task tags) and six
 * demo logins. Demo/test data only, refused in production.
 *
 * The names come from the owner's organisation chart. Replace them with fictional
 * names before any client or prospect sees the demo (D14).
 */
class DemoCompanySeeder extends Seeder
{
    /** Password for every demo account. Demo data only; meets the password rules. */
    public const PASSWORD = 'Demo#Meridian2026';

    /** [name, head (mockup id) or null] in display order. */
    private const DEPARTMENTS = [
        ['Management', 'E001'], ['General', null], ['System', null], ['Back End', 'E005'], ['WordPress', 'E021'],
        ['Front End', 'E007'], ['Mobile', 'E006'], ['Product Team', null], ['Admin', null], ['Customer Service', 'E003'],
    ];

    /** Lowest rung first: [mockup id, name, track, can lead a group, band min, band max, KPI target per quarter]. */
    private const DESIGNATIONS = [
        ['D0', 'Intern', 'Entry', false, 1000, 1800, 40],
        ['D1', 'Junior 1', 'Individual Contributor', false, 3000, 3800, 70],
        ['D2', 'Junior 2', 'Individual Contributor', false, 3800, 4800, 90],
        ['D3', 'Senior 1', 'Individual Contributor', true, 5000, 6500, 110],
        ['D4', 'Senior 2', 'Individual Contributor', true, 6500, 8500, 130],
        ['D5', 'Assistant Manager', 'Management', true, 8000, 10000, 110],
        ['D6', 'Manager', 'Management', true, 10000, 14000, 90],
        ['D7', 'General Manager', 'Management', true, 14000, 22000, 70],
        ['D9', 'C-Level', 'Executive', true, 22000, 45000, 40],
    ];

    /** name => [icon, probation months, typical duration, fixed end, converts to]. */
    private const EMPLOYMENT_TYPES = [
        'Internship' => ['graduation-cap', 0, '3–6 months', true, ['Contract', 'Permanent']],
        'Contract' => ['file-clock', 1, '6–24 months', true, ['Permanent']],
        'Permanent' => ['shield-check', 3, 'Open-ended', false, []],
    ];

    /** code => [name, colour, typical duration, description, points for levels 1..5]. */
    private const TASK_TAGS = [
        'F' => ['Quick task', '#15803d', '1 hour – 1 day', 'Small fixes, content edits, config changes.', [1, 2, 3, 4, 5]],
        'S' => ['Support / hotfix', '#0e7490', 'Same day', 'Production incidents and client tickets.', [2, 3, 4, 6, 8]],
        'E' => ['Small feature', '#1d4ed8', '1 – 3 days', 'A screen, an endpoint, a plugin tweak.', [6, 8, 10, 12, 14]],
        'D' => ['Feature', '#6d28d9', '3 – 5 days', 'End-to-end feature with tests.', [15, 18, 21, 24, 27]],
        'C' => ['Module', '#be185d', '1 – 2 weeks', 'A full module or integration.', [30, 36, 42, 48, 54]],
        'B' => ['Major / epic', '#b91c1c', '2+ weeks', 'Large build split across people.', [60, 72, 84, 96, 108]],
        'M' => ['Meeting', '#475569', 'Scheduled', 'Organiser earns points; attendance counts toward KPI.', [0, 1, 1, 2, 2]],
    ];

    private const LEVEL_NAMES = ['Routine', 'Standard', 'Involved', 'Complex', 'Critical'];

    /** Mockup id => [designation (mockup id), employment type]. */
    private const JOBS = [
        'E001' => ['D9', 'Permanent'], 'E002' => ['D7', 'Permanent'], 'E022' => ['D3', 'Permanent'], 'E023' => ['D2', 'Permanent'],
        'E024' => ['D3', 'Permanent'], 'E025' => ['D1', 'Permanent'], 'E026' => ['D1', 'Permanent'], 'E018' => ['D3', 'Permanent'],
        'E027' => ['D1', 'Permanent'], 'E005' => ['D5', 'Permanent'], 'E008' => ['D4', 'Permanent'], 'E028' => ['D4', 'Permanent'],
        'E029' => ['D3', 'Permanent'], 'E030' => ['D3', 'Permanent'], 'E031' => ['D3', 'Permanent'], 'E032' => ['D3', 'Permanent'],
        'E011' => ['D2', 'Permanent'], 'E033' => ['D2', 'Permanent'], 'E034' => ['D2', 'Permanent'], 'E035' => ['D2', 'Permanent'],
        'E036' => ['D2', 'Permanent'], 'E037' => ['D1', 'Permanent'], 'E021' => ['D5', 'Permanent'], 'E014' => ['D3', 'Contract'],
        'E017' => ['D1', 'Permanent'], 'E038' => ['D1', 'Permanent'], 'E016' => ['D0', 'Internship'], 'E039' => ['D1', 'Permanent'],
        'E007' => ['D5', 'Permanent'], 'E019' => ['D4', 'Permanent'], 'E040' => ['D3', 'Permanent'], 'E041' => ['D3', 'Permanent'],
        'E009' => ['D1', 'Permanent'], 'E010' => ['D1', 'Permanent'], 'E042' => ['D0', 'Internship'], 'E006' => ['D4', 'Permanent'],
        'E012' => ['D3', 'Permanent'], 'E043' => ['D3', 'Permanent'], 'E013' => ['D2', 'Permanent'], 'E044' => ['D4', 'Permanent'],
        'E045' => ['D3', 'Permanent'], 'E004' => ['D3', 'Permanent'], 'E003' => ['D5', 'Permanent'], 'E015' => ['D3', 'Permanent'],
        'E046' => ['D3', 'Permanent'], 'E047' => ['D2', 'Permanent'], 'E048' => ['D2', 'Permanent'], 'E049' => ['D2', 'Permanent'],
        'E050' => ['D1', 'Permanent'], 'E051' => ['D1', 'Permanent'], 'E052' => ['D1', 'Permanent'],
    ];

    /** Mockup id => [name, department, status, superior (mockup id)]. Superiors first. */
    private const EMPLOYEES = [
        'E001' => ['Ah Fa', 'Management', 'Permanent', null],
        'E002' => ['Leon Ong', 'Management', 'Permanent', 'E001'],
        'E022' => ['Wilson Chia', 'General', 'Permanent', 'E002'],
        'E023' => ['Jasper', 'General', 'Permanent', 'E022'],
        'E024' => ['Winson', 'General', 'Permanent', 'E002'],
        'E025' => ['Joseph', 'General', 'Permanent', 'E024'],
        'E026' => ['Yau Long', 'General', 'Permanent', 'E002'],
        'E018' => ['Jun Yee', 'System', 'Permanent', 'E002'],
        'E027' => ['Baefynne', 'System', 'Permanent', 'E018'],
        'E005' => ['Gan Siong Yi', 'Back End', 'Permanent', 'E002'],
        'E008' => ['Rex', 'Back End', 'Permanent', 'E005'],
        'E028' => ['Chris', 'Back End', 'Permanent', 'E008'],
        'E029' => ['Kai Seng (KS)', 'Back End', 'Permanent', 'E008'],
        'E030' => ['Yu Hang', 'Back End', 'Permanent', 'E008'],
        'E031' => ['Adam Lai', 'Back End', 'Permanent', 'E029'],
        'E032' => ['Leng', 'Back End', 'Permanent', 'E029'],
        'E011' => ['Gary', 'Back End', 'PIP', 'E008'],
        'E033' => ['Adam Koh', 'Back End', 'Permanent', 'E008'],
        'E034' => ['Zhong Lin', 'Back End', 'Permanent', 'E008'],
        'E035' => ['Jun Onn', 'Back End', 'Permanent', 'E008'],
        'E036' => ['JY', 'Back End', 'Permanent', 'E008'],
        'E037' => ['Darren', 'Back End', 'Permanent', 'E029'],
        'E021' => ['Wong Wei Yee', 'WordPress', 'Permanent', 'E002'],
        'E014' => ['Tang Sheng', 'WordPress', 'Contract', 'E021'],
        'E017' => ['James', 'WordPress', 'Probation', 'E014'],
        'E038' => ['Yi Ling', 'WordPress', 'Permanent', 'E014'],
        'E016' => ['Oswald', 'WordPress', 'Internship', 'E014'],
        'E039' => ['Ryan', 'WordPress', 'Permanent', 'E021'],
        'E007' => ['Tee Chien Yap', 'Front End', 'Permanent', 'E002'],
        'E019' => ['Jeff Yeoh', 'Front End', 'Permanent', 'E007'],
        'E040' => ['KY', 'Front End', 'Permanent', 'E019'],
        'E041' => ['AK', 'Front End', 'Permanent', 'E019'],
        'E009' => ['Ivan', 'Front End', 'Permanent', 'E019'],
        'E010' => ['Sin Yee', 'Front End', 'Probation', 'E019'],
        'E042' => ['Syndy', 'Front End', 'Internship', 'E019'],
        'E006' => ['Nicholas', 'Mobile', 'Promotion Probation', 'E002'],
        'E012' => ['Szen Yew', 'Mobile', 'Permanent', 'E006'],
        'E043' => ['Mei Yi', 'Mobile', 'Permanent', 'E006'],
        'E013' => ['Jonathan', 'Mobile', 'Permanent', 'E006'],
        'E044' => ['Joe Ho', 'Product Team', 'Permanent', 'E002'],
        'E045' => ['Ee Xin', 'Product Team', 'Permanent', 'E002'],
        'E004' => ['Ellie', 'Admin', 'Permanent', 'E002'],
        'E003' => ['Angie', 'Customer Service', 'Permanent', 'E002'],
        'E015' => ['Edwind', 'Customer Service', 'Permanent', 'E003'],
        'E046' => ['Brian', 'Customer Service', 'Permanent', 'E003'],
        'E047' => ['Iryse', 'Customer Service', 'Permanent', 'E003'],
        'E048' => ['Ryu', 'Customer Service', 'Permanent', 'E003'],
        'E049' => ['Carmen', 'Customer Service', 'Permanent', 'E003'],
        'E050' => ['Adrian', 'Customer Service', 'Permanent', 'E003'],
        'E051' => ['Catherine', 'Customer Service', 'Permanent', 'E003'],
        'E052' => ['Ronnie', 'Customer Service', 'Permanent', 'E003'],
    ];

    /** Group => [department, members, team leads (zero or more)]. */
    private const GROUPS = [
        'Team 1' => ['General', ['E022', 'E023'], ['E022']],
        'Team 2' => ['General', ['E024', 'E025'], ['E024']],
        'Full Stack' => ['General', ['E026'], []],
        'Business Analyst' => ['General', [], []],
        'System' => ['System', ['E018', 'E027'], ['E018']],
        'Back End' => ['Back End', ['E008', 'E028', 'E029', 'E030', 'E031', 'E032', 'E011', 'E033', 'E034', 'E035', 'E036', 'E037'], ['E008', 'E029']],
        'WordPress' => ['WordPress', ['E021', 'E014', 'E017', 'E038', 'E016'], ['E021']],
        'IT Support' => ['WordPress', ['E039'], []],
        'FE Developer' => ['Front End', ['E019', 'E040', 'E041', 'E009', 'E010', 'E042'], ['E019']],
        'Mobile' => ['Mobile', ['E006', 'E012', 'E043', 'E013'], ['E006']],
        'Product Team' => ['Product Team', ['E044', 'E045'], []],
        'Admin' => ['Admin', ['E004'], []],
        'Customer Service' => ['Customer Service', ['E015', 'E046', 'E047', 'E048', 'E049', 'E050', 'E051', 'E052'], []],
    ];

    /** email => [name, role, mockup employee id] */
    public const ACCOUNTS = [
        'tang@hajimi.dev' => ['Tang', RoleKey::Sysadmin, null],
        'ahfa@hajimi.my' => ['Ah Fa', RoleKey::Exec, 'E001'],
        'ellie@hajimi.my' => ['Ellie', RoleKey::Hr, 'E004'],
        'teechienyap@hajimi.my' => ['Tee Chien Yap', RoleKey::Lead, 'E007'],
        'jeffyeoh@hajimi.my' => ['Jeff Yeoh', RoleKey::Staff, 'E019'],
        'sinyee@hajimi.my' => ['Sin Yee', RoleKey::Staff, 'E010'],
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
            app(SettingsService::class)->update([
                'company_name' => 'Hajimi Technologies Sdn Bhd',
                'employee_no_format' => 'HJ-{seq}',
                'employee_no_digits' => 4,
                'employee_no_next' => count(self::EMPLOYEES) + 1,
            ], CompanySettings::current()->version, null);

            [$designations, $types] = $this->seedCatalogues();

            /** @var array<string, Department> $departments */
            $departments = [];
            foreach (self::DEPARTMENTS as $i => [$name]) {
                $departments[$name] = Department::query()->create(['name' => $name, 'sort' => $i * 10]);
            }

            /** @var array<string, Employee> $employees */
            $employees = [];
            $n = 0;
            foreach (self::EMPLOYEES as $id => [$name, $dept, $status, $superior]) {
                $n++;
                $employee = new Employee([
                    'employee_no' => 'HJ-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
                    'full_name' => $name,
                    'work_email' => self::email($name).'@hajimi.my',
                    'status' => $status,
                    'join_date' => sprintf('20%02d-%02d-%02d', 19 + $n % 7, 1 + ($n * 5) % 12, 1 + ($n * 7) % 27),
                ]);
                $employee->forceFill([
                    'department_id' => $departments[$dept]->id,
                    'designation_id' => $designations[self::JOBS[$id][0]]->id,
                    'employment_type_id' => $types[self::JOBS[$id][1]]->id,
                    'superior_id' => $superior === null ? null : $employees[$superior]->id,
                ])->save();
                $employees[$id] = $employee;
            }

            foreach (self::DEPARTMENTS as [$name, $head]) {
                if ($head !== null) {
                    $departments[$name]->update(['head_employee_id' => $employees[$head]->id]);
                }
            }

            $teams = app(TeamService::class);
            foreach (self::GROUPS as $name => [$dept, $members, $leads]) {
                $team = Team::query()->create(['name' => $name, 'department_id' => $departments[$dept]->id]);
                foreach ($members as $member) {
                    $teams->addMember($team, $employees[$member]);
                }
                foreach ($leads as $lead) {
                    $teams->addLead($team, $employees[$lead]);
                }
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

    /**
     * @return array{0: array<string, Designation>, 1: array<string, EmploymentType>} keyed by mockup id / type name
     */
    private function seedCatalogues(): array
    {
        $designations = [];
        foreach (self::DESIGNATIONS as $level => [$id, $name, $track, $canLead, $min, $max, $target]) {
            $designation = new Designation([
                'name' => $name, 'track' => $track, 'can_lead_team' => $canLead,
                'salary_min' => $min, 'salary_max' => $max, 'kpi_target_points' => $target,
            ]);
            $designation->forceFill(['level' => $level])->save();
            $designations[$id] = $designation;
        }

        $typeService = app(EmploymentTypeService::class);
        $types = [];
        foreach (self::EMPLOYMENT_TYPES as $name => [$icon, $probation, $duration, $fixedEnd]) {
            $types[$name] = $typeService->create([
                'name' => $name, 'icon' => $icon, 'default_probation_months' => $probation,
                'typical_duration' => $duration, 'has_fixed_end' => $fixedEnd, 'renewal_reminder_days' => $fixedEnd ? 30 : null,
            ], []);
        }
        foreach (self::EMPLOYMENT_TYPES as $name => [, , , , $convertsTo]) {
            $types[$name]->convertsTo()->sync(array_map(fn (string $to) => $types[$to]->id, $convertsTo));
        }

        $tagService = app(TaskTagService::class);
        foreach (self::TASK_TAGS as $code => [$name, $color, $duration, $description, $points]) {
            $tagService->create(
                ['code' => $code, 'name' => $name, 'color' => $color, 'typical_duration' => $duration, 'description' => $description],
                array_map(fn (int $p, int $i) => ['name' => self::LEVEL_NAMES[$i], 'points' => $p], $points, array_keys($points)),
            );
        }

        return [$designations, $types];
    }

    /** "Kai Seng (KS)" -> "kaiseng", "Tee Chien Yap" -> "teechienyap". */
    public static function email(string $name): string
    {
        return (string) preg_replace('/[^a-z]/', '', mb_strtolower((string) preg_replace('/\(.*?\)/', '', $name)));
    }
}
