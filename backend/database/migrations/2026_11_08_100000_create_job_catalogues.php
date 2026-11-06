<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 01 §9.4 (D19–D22): the company-editable catalogues.
 *
 * - designations: the career ladder. `level` is the rung (0 = lowest) and is
 *   unique; the order is changed only as a whole (DesignationService::reorder).
 * - employment_types: any number of types, with the types each may convert to.
 * - task_tags + task_tag_levels: each tag has one or more levels with points.
 * - employee number format on company_settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('designations', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->string('name', 100)->unique();
            $table->enum('track', ['Entry', 'Individual Contributor', 'Management', 'Executive']);
            $table->unsignedSmallInteger('level')->unique();
            // D17: interns and juniors can't lead a group. A flag, not a level threshold,
            // so reordering the ladder never silently changes who may lead.
            $table->boolean('can_lead_team')->default(true);
            $table->decimal('salary_min', 12, 2)->nullable();
            $table->decimal('salary_max', 12, 2)->nullable();
            $table->unsignedSmallInteger('kpi_target_points')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->datetimes6();
        });
        DB::statement('ALTER TABLE designations ADD CONSTRAINT designations_salary_band CHECK (salary_min IS NULL OR salary_max IS NULL OR salary_min <= salary_max)');
        DB::statement("ALTER TABLE designations ADD CONSTRAINT designations_name_not_blank CHECK (TRIM(name) <> '')");

        Schema::create('employment_types', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->string('name', 60)->unique();
            $table->string('icon', 40)->default('briefcase');
            $table->unsignedTinyInteger('default_probation_months')->default(0);
            $table->string('typical_duration', 60)->nullable();
            $table->boolean('has_fixed_end')->default(false);
            $table->unsignedSmallInteger('renewal_reminder_days')->nullable();
            $table->string('notes', 500)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->datetimes6();
        });
        DB::statement('ALTER TABLE employment_types ADD CONSTRAINT employment_types_probation CHECK (default_probation_months <= 6)');
        DB::statement('ALTER TABLE employment_types ADD CONSTRAINT employment_types_reminder CHECK (renewal_reminder_days IS NULL OR renewal_reminder_days IN (14, 30, 60))');
        DB::statement("ALTER TABLE employment_types ADD CONSTRAINT employment_types_name_not_blank CHECK (TRIM(name) <> '')");

        Schema::create('employment_type_conversions', function (Blueprint $table) {
            $table->foreignId('from_type_id')->constrained('employment_types')->cascadeOnDelete();
            $table->foreignId('to_type_id')->constrained('employment_types')->cascadeOnDelete();
            $table->primary(['from_type_id', 'to_type_id']);
            $table->index('to_type_id');
        });
        DB::statement('ALTER TABLE employment_type_conversions ADD CONSTRAINT employment_type_conversions_not_self CHECK (from_type_id <> to_type_id)');

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('designation_id')->nullable()->after('department_id')->constrained('designations')->restrictOnDelete();
            $table->foreignId('employment_type_id')->nullable()->after('designation_id')->constrained('employment_types')->restrictOnDelete();
        });

        Schema::create('task_tags', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->string('code', 3)->unique();
            $table->string('name', 60);
            $table->char('color', 7);
            $table->string('typical_duration', 60)->nullable();
            $table->string('description', 300)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->datetimes6();
        });
        DB::statement("ALTER TABLE task_tags ADD CONSTRAINT task_tags_code_format CHECK (BINARY code REGEXP '^[A-Z][A-Z0-9]{0,2}$')");
        DB::statement("ALTER TABLE task_tags ADD CONSTRAINT task_tags_color_hex CHECK (color REGEXP '^#[0-9A-Fa-f]{6}$')");

        // Levels are numbered 1..n with no gaps (TaskTagService writes them as a whole).
        Schema::create('task_tag_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_tag_id')->constrained('task_tags')->cascadeOnDelete();
            $table->unsignedTinyInteger('level');
            $table->string('name', 60);
            $table->unsignedSmallInteger('points');
            $table->unique(['task_tag_id', 'level']);
        });
        DB::statement('ALTER TABLE task_tag_levels ADD CONSTRAINT task_tag_levels_level CHECK (level BETWEEN 1 AND 20)');

        Schema::table('company_settings', function (Blueprint $table) {
            // Tokens: {seq} (required), {yyyy}, {yy}, {dept}. See EmployeeNumberFormat.
            $table->string('employee_no_format', 20)->default('EMP-{seq}')->after('former_employee_policy');
            $table->unsignedTinyInteger('employee_no_digits')->default(4)->after('employee_no_format');
            $table->unsignedInteger('employee_no_next')->default(1)->after('employee_no_digits');
        });
        DB::statement("ALTER TABLE company_settings ADD CONSTRAINT company_settings_employee_no_format CHECK (LOCATE('{seq}', employee_no_format) > 0)");
        DB::statement('ALTER TABLE company_settings ADD CONSTRAINT company_settings_employee_no_digits CHECK (employee_no_digits BETWEEN 3 AND 8)');
        DB::statement('ALTER TABLE company_settings ADD CONSTRAINT company_settings_employee_no_next CHECK (employee_no_next >= 1)');
    }

    public function down(): void
    {
        foreach (['company_settings_employee_no_format', 'company_settings_employee_no_digits', 'company_settings_employee_no_next'] as $check) {
            DB::statement("ALTER TABLE company_settings DROP CONSTRAINT {$check}");
        }
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn(['employee_no_format', 'employee_no_digits', 'employee_no_next']);
        });
        Schema::dropIfExists('task_tag_levels');
        Schema::dropIfExists('task_tags');
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['designation_id']);
            $table->dropForeign(['employment_type_id']);
            $table->dropColumn(['designation_id', 'employment_type_id']);
        });
        Schema::dropIfExists('employment_type_conversions');
        Schema::dropIfExists('employment_types');
        Schema::dropIfExists('designations');
    }
};
