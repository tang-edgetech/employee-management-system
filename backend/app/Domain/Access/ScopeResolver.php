<?php

namespace App\Domain\Access;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Works out a user's Scope (brief §5.2), once per request:
 *
 * - sysadmin, exec, hr: everyone.
 * - lead: themselves and everyone below them, plus the members of every team they
 *   lead and everyone below those members.
 * - staff: themselves and everyone below them in the reporting chain (a senior
 *   who supervises juniors sees those juniors).
 * - an account with no employee record (other than the roles above): nobody.
 *
 * "Below" means reports_of(): the recursive closure of superior_id.
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
        $leadTeamIds = $self === null ? [] : DB::table('teams')->where('lead_employee_id', $self)->pluck('id')->all();
        $hasReports = $self !== null && DB::table('employees')->where('superior_id', $self)->whereNull('deleted_at')->exists();

        if (in_array($user->roleKey(), [RoleKey::Sysadmin, RoleKey::Exec, RoleKey::Hr], true)) {
            return Scope::everyone($hasReports, $leadTeamIds !== []);
        }
        if ($self === null) {
            return Scope::only([], false, false);
        }

        $roots = [$self];
        if ($user->roleKey() === RoleKey::Lead && $leadTeamIds !== []) {
            $roots = array_merge($roots, DB::table('team_members')->whereIn('team_id', $leadTeamIds)->pluck('employee_id')->all());
        }

        return Scope::only($this->withReports($roots), $hasReports, $leadTeamIds !== []);
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
