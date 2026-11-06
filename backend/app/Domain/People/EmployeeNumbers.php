<?php

namespace App\Domain\People;

use App\Domain\Audit\AuditLogger;
use App\Domain\Settings\CompanySettings;
use App\Domain\Shared\Concurrency\ETag;
use App\Exceptions\ApiProblem;
use Illuminate\Support\Facades\DB;

/**
 * Issues employee numbers from the company's format (D22) and renumbers everyone
 * when the company asks for it. Changing the format alone (PATCH /settings)
 * only affects new employees.
 */
final class EmployeeNumbers
{
    public function format(CompanySettings $settings): EmployeeNumberFormat
    {
        return new EmployeeNumberFormat($settings->employee_no_format, $settings->employee_no_digits);
    }

    /**
     * Takes the next free number for a new employee. Must run inside the
     * transaction that creates the employee: the settings row stays locked until
     * it commits, so two hires never get the same number. Skips numbers already
     * taken (for example typed in by hand).
     */
    public function issue(Employee $employee): string
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Issue employee numbers inside the transaction that creates the employee.');
        }

        /** @var CompanySettings $settings */
        $settings = CompanySettings::query()->lockForUpdate()->findOrFail(1);
        $format = $this->format($settings);
        $department = $employee->department()->value('name');

        $seq = $settings->employee_no_next;
        do {
            $number = $format->format($seq++, $employee->join_date, $department);
        } while (Employee::withTrashed()->where('employee_no', $number)->exists());

        $settings->employee_no_next = $seq;
        $settings->save();

        return $number;
    }

    /** What the next new employee would get, without taking it. */
    public function preview(?Employee $employee = null): string
    {
        $settings = CompanySettings::current();

        return $this->format($settings)->format($settings->employee_no_next, $employee?->join_date, $employee?->department?->name);
    }

    /**
     * Gives every employee (including former ones) a new number in join-date order,
     * starting at 1, saves the format, and continues new numbers after the last one.
     *
     * @return array<string, array{0: string, 1: string}> public_id => [old, new] for changed numbers
     */
    public function renumberAll(string $pattern, int $digits, int $expectedVersion): array
    {
        if (($problem = EmployeeNumberFormat::problem($pattern, $digits)) !== null) {
            throw ApiProblem::unprocessable('EMPLOYEE_NO_FORMAT_INVALID', $problem);
        }

        return DB::transaction(function () use ($pattern, $digits, $expectedVersion) {
            /** @var CompanySettings $settings */
            $settings = CompanySettings::query()->lockForUpdate()->findOrFail(1);
            ETag::assertMatches($expectedVersion, $settings->version);

            $format = new EmployeeNumberFormat($pattern, $digits);
            $employees = Employee::withTrashed()->with('department:id,name')->lockForUpdate()
                ->orderBy('join_date')->orderBy('id')->get(['id', 'public_id', 'employee_no', 'join_date', 'department_id']);

            $new = [];
            foreach ($employees->values() as $i => $employee) {
                $new[$employee->id] = $format->format($i + 1, $employee->join_date, $employee->department?->name);
            }
            if (count(array_unique($new)) !== count($new)) {
                throw ApiProblem::unprocessable('EMPLOYEE_NO_NOT_UNIQUE', 'This format gives two employees the same number.');
            }

            // employee_no is unique: park every number first, then write the final ones.
            foreach ($employees as $employee) {
                Employee::withTrashed()->whereKey($employee->id)->update(['employee_no' => '~'.$employee->id]);
            }
            $changed = [];
            foreach ($employees as $employee) {
                $moved = $new[$employee->id] !== $employee->employee_no;
                Employee::withTrashed()->whereKey($employee->id)->update(
                    ['employee_no' => $new[$employee->id]] + ($moved ? ['version' => DB::raw('version + 1')] : []),
                );
                if ($moved) {
                    $changed[$employee->public_id] = [$employee->employee_no, $new[$employee->id]];
                }
            }

            $settings->employee_no_format = $pattern;
            $settings->employee_no_digits = $digits;
            $settings->employee_no_next = $employees->count() + 1;
            $settings->version++;
            $settings->save();

            app(AuditLogger::class)->record('employees.renumbered', $settings, [
                'after' => ['employee_no_format' => $pattern, 'employee_no_digits' => $digits],
                'changed' => $changed,
            ]);

            return $changed;
        });
    }
}
