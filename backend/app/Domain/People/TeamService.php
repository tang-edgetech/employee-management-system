<?php

namespace App\Domain\People;

use App\Domain\Audit\AuditLogger;
use App\Exceptions\ApiProblem;
use Illuminate\Support\Facades\DB;

/**
 * Team membership and leads. Rule (brief §6.1): the lead must be a member, so
 * making someone lead adds them, and the lead can't be removed while leading.
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
        if ($team->lead_employee_id === $employee->id) {
            throw ApiProblem::conflict('TEAM_LEAD_MEMBERSHIP', 'Choose a new lead before removing the current lead from the team.');
        }

        DB::transaction(function () use ($team, $employee) {
            if ($team->members()->detach($employee->id) > 0) {
                app(AuditLogger::class)->record('teams.member_removed', $team, ['before' => ['employee_id' => $employee->id]]);
            }
        });
    }

    public function setLead(Team $team, ?Employee $lead): void
    {
        DB::transaction(function () use ($team, $lead) {
            if ($lead !== null) {
                $this->addMember($team, $lead);
            }

            $team->lead_employee_id = $lead?->id;
            if ($team->isDirty('lead_employee_id')) {
                $team->version = $team->version + 1;
                $team->save();
            }
        });
    }
}
