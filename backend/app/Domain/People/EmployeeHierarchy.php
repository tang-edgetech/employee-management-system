<?php

namespace App\Domain\People;

use App\Domain\Audit\AuditLogger;
use App\Exceptions\ApiProblem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Changes reporting lines. Refuses a superior that would create a loop (someone
 * ending up above themselves), with a clear error; the database trigger is the
 * backstop (ADR 0001 §5.12).
 */
final class EmployeeHierarchy
{
    public function setSuperior(Employee $employee, ?Employee $superior): void
    {
        DB::transaction(function () use ($employee, $superior) {
            // Lock both rows so two concurrent changes can't each pass the check and form a loop together.
            Employee::query()->whereKey(array_filter([$employee->id, $superior?->id]))->lockForUpdate()->get();

            if ($superior !== null && $this->wouldLoop($employee->id, $superior->id)) {
                throw self::loop();
            }

            $before = $employee->superior_id;
            $employee->superior_id = $superior?->id;
            if (! $employee->isDirty('superior_id')) {
                return;
            }

            try {
                $employee->version = $employee->version + 1;
                $employee->saveQuietly();
            } catch (QueryException $e) {
                throw str_contains($e->getMessage(), 'REPORTING_LOOP') ? self::loop() : $e;
            }

            app(AuditLogger::class)->record('employees.superior_changed', $employee, [
                'before' => ['superior_id' => $before],
                'after' => ['superior_id' => $employee->superior_id],
            ]);
        });
    }

    /** True if $employeeId appears in the chain from $superiorId upwards (or is the superior). */
    public function wouldLoop(int $employeeId, int $superiorId): bool
    {
        if ($employeeId === $superiorId) {
            return true;
        }

        return DB::selectOne(<<<'SQL'
            WITH RECURSIVE chain (id) AS (
                SELECT ?
                UNION
                SELECT e.superior_id FROM employees e JOIN chain c ON e.id = c.id WHERE e.superior_id IS NOT NULL
            )
            SELECT EXISTS (SELECT 1 FROM chain WHERE id = ?) AS found
            SQL, [$superiorId, $employeeId])->found === 1;
    }

    private static function loop(): ApiProblem
    {
        return ApiProblem::unprocessable('REPORTING_LOOP', 'This reporting line would make someone report to themselves, directly or through others.');
    }
}
