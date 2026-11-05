<?php

namespace App\Domain\Access;

use Illuminate\Database\Eloquent\Builder;

/**
 * The set of employees a user may see and act on (brief §5.2): everyone, or a
 * fixed list of employee IDs. Apply it to every query that lists or loads
 * employees, so the rule lives in one place.
 */
final class Scope
{
    /**
     * @param  list<int>  $employeeIds
     */
    private function __construct(
        public readonly bool $all,
        public readonly array $employeeIds,
        public readonly bool $hasReports,
        public readonly bool $leadsTeams,
        public readonly bool $headsDepartment = false,
    ) {}

    public static function everyone(bool $hasReports = false, bool $leadsTeams = false, bool $headsDepartment = false): self
    {
        return new self(true, [], $hasReports, $leadsTeams, $headsDepartment);
    }

    /**
     * @param  list<int>  $employeeIds
     */
    public static function only(array $employeeIds, bool $hasReports, bool $leadsTeams, bool $headsDepartment = false): self
    {
        $ids = array_values(array_unique(array_map('intval', $employeeIds)));
        sort($ids);

        return new self(false, $ids, $hasReports, $leadsTeams, $headsDepartment);
    }

    public function includes(int $employeeId): bool
    {
        return $this->all || in_array($employeeId, $this->employeeIds, true);
    }

    /**
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function apply(Builder $query, string $column = 'id'): Builder
    {
        return $this->all ? $query : $query->whereIn($query->qualifyColumn($column), $this->employeeIds);
    }
}
