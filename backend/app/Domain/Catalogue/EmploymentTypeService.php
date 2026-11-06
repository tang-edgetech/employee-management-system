<?php

namespace App\Domain\Catalogue;

use App\Domain\Audit\AuditLogger;
use App\Domain\People\Employee;
use App\Domain\Shared\Concurrency\ETag;
use App\Exceptions\ApiProblem;
use Illuminate\Support\Facades\DB;

/**
 * Employment types (D20). Any number can be added, edited or deleted, but at least
 * one must remain. Deleting a type that employees still have needs a type to move
 * them to: they are moved and the type deleted in one transaction.
 */
final class EmploymentTypeService
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>  $convertsTo  employment type ids
     */
    public function create(array $data, array $convertsTo): EmploymentType
    {
        return DB::transaction(function () use ($data, $convertsTo) {
            $type = new EmploymentType($data);
            $type->sort = (int) EmploymentType::query()->max('sort') + 10;
            $type->save();
            $type->convertsTo()->sync($convertsTo);

            return $type;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  ?list<int>  $convertsTo  null leaves the conversions unchanged
     */
    public function update(EmploymentType $type, array $data, ?array $convertsTo, int $expectedVersion): EmploymentType
    {
        return DB::transaction(function () use ($type, $data, $convertsTo, $expectedVersion) {
            /** @var EmploymentType $locked */
            $locked = EmploymentType::query()->lockForUpdate()->findOrFail($type->id);
            ETag::assertMatches($expectedVersion, $locked->version);

            $locked->fill($data);
            $conversionsChanged = false;
            if ($convertsTo !== null) {
                $convertsTo = array_values(array_diff($convertsTo, [$locked->id]));
                $result = $locked->convertsTo()->sync($convertsTo);
                $conversionsChanged = $result['attached'] !== [] || $result['detached'] !== [];
            }

            if ($locked->isDirty() || $conversionsChanged) {
                $locked->version++;
                $locked->save();
            }

            return $locked;
        });
    }

    public function delete(EmploymentType $type, ?EmploymentType $moveTo, int $expectedVersion): int
    {
        return DB::transaction(function () use ($type, $moveTo, $expectedVersion) {
            /** @var EmploymentType $locked */
            $locked = EmploymentType::query()->lockForUpdate()->findOrFail($type->id);
            ETag::assertMatches($expectedVersion, $locked->version);

            if (EmploymentType::query()->count() === 1) {
                throw ApiProblem::conflict('LAST_EMPLOYMENT_TYPE', 'Keep at least one employment type.');
            }
            if ($moveTo !== null && $moveTo->id === $locked->id) {
                throw ApiProblem::unprocessable('MOVE_TO_SAME_TYPE', 'Choose a different type to move employees to.');
            }

            $employees = Employee::query()->where('employment_type_id', $locked->id)->lockForUpdate()->get(['id', 'public_id', 'employee_no']);
            if ($employees->isNotEmpty() && $moveTo === null) {
                throw ApiProblem::conflict('EMPLOYMENT_TYPE_IN_USE', "{$employees->count()} employee(s) have {$locked->name}. Choose a type to move them to.");
            }

            if ($employees->isNotEmpty()) {
                Employee::query()->whereKey($employees->modelKeys())->update([
                    'employment_type_id' => $moveTo?->id,
                    'version' => DB::raw('version + 1'),
                ]);
                app(AuditLogger::class)->record('employment_types.employees_moved', $locked, [
                    'before' => ['employment_type' => $locked->name],
                    'after' => ['employment_type' => $moveTo?->name],
                    'employees' => $employees->pluck('public_id')->all(),
                ]);
            }

            $locked->delete();

            return $employees->count();
        });
    }
}
