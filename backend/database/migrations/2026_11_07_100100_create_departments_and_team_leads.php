<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 01 §9.2 (D12): a minimal departments table with an optional head of department,
 * and groups (teams) with zero, one or several team leads. Reporting lines stay per
 * person (employees.superior_id) and are never derived from team leads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->string('name', 100)->unique();
            // At most one head per department (a column, not a list); any designation may head one.
            $table->foreignId('head_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->datetimes6();
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('work_email')->constrained('departments')->restrictOnDelete();
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('name')->constrained('departments')->restrictOnDelete();
            $table->dropForeign(['lead_employee_id']);
            $table->dropColumn('lead_employee_id');
        });

        Schema::create('team_leads', function (Blueprint $table) {
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->primary(['team_id', 'employee_id']);
            $table->index('employee_id');
            // A team lead must also be a member of the group.
            $table->foreign(['team_id', 'employee_id'], 'team_leads_member_fk')
                ->references(['team_id', 'employee_id'])->on('team_members')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_leads');
        Schema::table('teams', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
            $table->dropColumn('department_id');
            $table->foreignId('lead_employee_id')->nullable()->constrained('employees')->restrictOnDelete();
        });
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
            $table->dropColumn('department_id');
        });
        Schema::dropIfExists('departments');
    }
};
