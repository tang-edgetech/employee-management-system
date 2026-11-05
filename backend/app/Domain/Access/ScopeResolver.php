<?php

namespace App\Domain\Access;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Works out a user's Scope (brief §5.2), once per request:
 *
 * - sysadmin, exec, hr: everyone.
 * - anyone with an employee record: themselves and everyone below them in the
 *   reporting lines (a senior who supervises juniors sees those juniors);
 * - plus, for a team lead (any role): the members of every group they lead and
 *   everyone below those members;
 * - plus, for a head of department (any role): everyone in that department.
 * - an account with no employee record (other than the roles above): nobody.
 *
 * "Below" means reports_of(): the recursive closure of superior_id. Reporting
 * lines are set per person (plan 01 §9.2), never derived from team leads.
 */
final class ScopeResolver
{
    /** @var array<int, Scope> user id => scope, for this request */
    private array $resolved = [];

    public function for(User $user): Scope
    {
        return $this->resolved[$user->id] ??= $this->resolve($user);
    }

    private function resolve(User $user): Scope
    {
        $self = $user->employee_id;
        $leadTeamIds = $self === null ? [] : DB::table('team_leads')->where('employee_id', $self)->pluck('team_id')->all();
        $headOf = $self === null ? [] : DB::table('departments')->where('head_employee_id', $self)->pluck('id')->all();
        $hasReports = $self !== null && DB::table('employees')->where('superior_id', $self)->whereNull('deleted_at')->exists();

        if (in_array($user->roleKey(), [RoleKey::Sysadmin, RoleKey::Exec, RoleKey::Hr], true)) {
            return Scope::everyone($hasReports, $leadTeamIds !== [], $headOf !== []);
        }
        if ($self === null) {
            return Scope::only([], false, false, false);
        }

        $roots = [$self];
        if ($leadTeamIds !== []) {
            $roots = array_merge($roots, DB::table('team_members')->whereIn('team_id', $leadTeamIds)->pluck('employee_id')->all());
        }
        if ($headOf !== []) {
            $roots = array_merge($roots, DB::table('employees')->whereIn('department_id', $headOf)->whereNull('deleted_at')->pluck('id')->all());
        }

        return Scope::only($this->withReports(array_map('intval', $roots)), $hasReports, $leadTeamIds !== [], $headOf !== []);
    }

    /**
     * The given employees plus everyone below them.
     *
     * @param  list<int>  $roots
     * @return list<int>
     */
    public function withReports(array $roots): array
    {
        if ($roots === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($roots), '?'));

        return array_map('intval', array_column(DB::select(<<<SQL
            WITH RECURSIVE below (id) AS (
                SELECT id FROM employees WHERE id IN ({$placeholders}) AND deleted_at IS NULL
                UNION
                SELECT e.id FROM employees e JOIN below b ON e.superior_id = b.id WHERE e.deleted_at IS NULL
            )
            SELECT id FROM below
            SQL, $roots), 'id'));
    }

    /** For tests and after changes to reporting lines or teams within a request. */
    public function forget(): void
    {
        $this->resolved = [];
    }
}
