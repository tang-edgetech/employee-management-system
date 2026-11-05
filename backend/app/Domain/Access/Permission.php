<?php

namespace App\Domain\Access;

/**
 * The permission catalogue of brief §5.3. Keys are fixed in code; the grants per
 * role live in role_permissions and are editable (except System Admin, who
 * always has everything). Adding a permission needs a migration that inserts it.
 */
enum Permission: string
{
    case OrgStructureManage = 'org.structure.manage';
    case OrgTeamsManage = 'org.teams.manage';
    case OrgTaskTagsManage = 'org.task_tags.manage';
    case PeopleEdit = 'people.edit';
    case PeopleImport = 'people.import';
    case PeopleSalaryView = 'people.salary.view';
    case PeopleLifecycle = 'people.lifecycle';
    case PeopleDataReviewApprove = 'people.data_review.approve';
    case CandidateCreate = 'candidate.create';
    case CandidateViewIdentity = 'candidate.view_identity';
    case InterviewManage = 'interview.manage';
    case ProjectManage = 'project.manage';
    case ExtensionApprove = 'extension.approve';
    case DelayRecord = 'delay.record';
    case KpiViewAll = 'kpi.view_all';
    case KpiConfigure = 'kpi.configure';
    case SystemSettings = 'system.settings';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
