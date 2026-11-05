<?php

namespace App\Domain\People;

use App\Domain\Audit\AuditLogger;
use App\Exceptions\ApiProblem;
use Illuminate\Support\Facades\DB;

/**
 * Group membership and team leads (plan 01 §9.2). A group may have no team lead,
 * one, or several; every team lead must be a member (also enforced by a foreign
 * key). Removing a member also removes them as a team lead.
 */
final class TeamService
{
    public function addMember(Team $team, Employee $employee): void
    {
        DB::transaction(function () use ($team, $employee) {
            $added = $team->members()->syncWithoutDetaching([$employee->id])['attached'] !== [];
            if ($added) {
                app(AuditLogger::class)->record('teams.member_added', $team, ['after' => ['employee_id' => $employee->id]]);
            }
        });
    }

    public function removeMember(Team $team, Employee $employee): void
    {
        DB::transaction(function () use ($team, $employee) {
            $wasLead = $team->leads()->detach($employee->id) > 0;
            if ($team->members()->detach($employee->id) > 0) {
                app(AuditLogger::class)->record('teams.member_removed', $team, [
                    'before' => ['employee_id' => $employee->id, 'team_lead' => $wasLead],
                ]);
            }
        });
    }

    public function addLead(Team $team, Employee $employee): void
    {
        if (! $team->members()->whereKey($employee->id)->exists()) {
            throw ApiProblem::unprocessable('TEAM_LEAD_NOT_MEMBER', 'Add this person to the group before making them a team lead.');
        }

        DB::transaction(function () use ($team, $employee) {
            if ($team->leads()->syncWithoutDetaching([$employee->id])['attached'] !== []) {
                app(AuditLogger::class)->record('teams.lead_added', $team, ['after' => ['employee_id' => $employee->id]]);
            }
        });
    }

    public function removeLead(Team $team, Employee $employee): void
    {
        DB::transaction(function () use ($team, $employee) {
            if ($team->leads()->detach($employee->id) > 0) {
                app(AuditLogger::class)->record('teams.lead_removed', $team, ['before' => ['employee_id' => $employee->id]]);
            }
        });
    }
}
