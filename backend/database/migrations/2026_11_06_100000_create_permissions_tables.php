<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permission catalogue and the editable role → permission matrix, seeded with the
 * defaults of brief §5.3 (labels from the mockup). System Admin has no rows: it
 * always has every permission and can't be edited.
 */
return new class extends Migration
{
    /** key => [group, label, roles granted by default] */
    private const CATALOGUE = [
        'org.structure.manage' => ['Organization', 'Manage departments, designations & employment types', ['exec', 'hr']],
        'org.teams.manage' => ['Organization', 'Manage teams & team leads', ['exec', 'hr']],
        'org.task_tags.manage' => ['Organization', 'Manage task tags & points', ['exec', 'lead']],
        'people.edit' => ['People', 'Edit employee records', ['exec', 'hr', 'lead']],
        'people.import' => ['People', 'Import employees (CSV)', ['hr']],
        'people.salary.view' => ['People', 'View salary', ['exec', 'hr']],
        'people.lifecycle' => ['People', 'Probation, increment & promotion', ['exec', 'hr']],
        'people.data_review.approve' => ['People', 'Approve annual personal-data updates', ['hr']],
        'candidate.create' => ['Recruitment', 'Add candidates manually', ['hr']],
        'candidate.view_identity' => ['Recruitment', 'View NRIC / passport', ['exec', 'hr']],
        'interview.manage' => ['Recruitment', 'Schedule interviews & record results', ['exec', 'hr', 'lead']],
        'project.manage' => ['Work', 'Create projects & assign tasks', ['exec', 'lead']],
        'extension.approve' => ['Work', 'Approve extension (as superior / PM)', ['exec', 'lead', 'staff']],
        'delay.record' => ['Work', 'Record delays & blockers', ['exec', 'lead', 'staff']],
        'kpi.view_all' => ['KPI', 'View all KPI scores', ['exec', 'hr']],
        'kpi.configure' => ['KPI', 'Edit KPI formula & import external data', ['exec', 'hr']],
        'system.settings' => ['System', 'Global settings & 2FA policy', []],
    ];

    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->charset('ascii')->collation('ascii_bin')->unique();
            $table->string('group', 30);
            $table->string('label', 120);
            $table->unsignedSmallInteger('sort');
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('access_roles')->restrictOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->restrictOnDelete();
            $table->boolean('granted')->default(false);
            $table->primary(['role_id', 'permission_id']);
        });

        $roles = DB::table('access_roles')->where('key', '!=', 'sysadmin')->pluck('id', 'key');
        $sort = 0;

        foreach (self::CATALOGUE as $key => [$group, $label, $grantedTo]) {
            $permissionId = DB::table('permissions')->insertGetId([
                'key' => $key, 'group' => $group, 'label' => $label, 'sort' => $sort += 10,
            ]);

            foreach ($roles as $roleKey => $roleId) {
                DB::table('role_permissions')->insert([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'granted' => in_array($roleKey, $grantedTo, true),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
    }
};
