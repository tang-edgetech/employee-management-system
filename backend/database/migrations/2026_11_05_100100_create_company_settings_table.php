<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row of company-wide settings per install (ADR 0002). The app validates every
 * field; the CHECK constraints here are the backstop if anything bypasses it.
 * logo_file_id gets its foreign key when the files table arrives (M5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();

            $table->string('company_name', 150);
            $table->unsignedBigInteger('logo_file_id')->nullable();
            $table->string('timezone', 64)->default('Asia/Kuala_Lumpur');
            $table->char('country', 2)->default('MY');
            $table->char('currency', 3)->default('MYR');
            $table->json('working_days');
            $table->string('holiday_region', 10)->nullable();

            $table->boolean('two_fa_enabled')->default(false);
            $table->json('two_fa_roles');
            $table->unsignedSmallInteger('session_timeout_minutes')->default(30);

            $table->unsignedTinyInteger('default_probation_months')->default(3);
            $table->unsignedTinyInteger('probation_reminder_days')->default(14);
            $table->unsignedTinyInteger('pip_default_days')->default(60);

            $table->unsignedTinyInteger('checkin_every_workdays')->default(2);
            $table->boolean('checkin_mandatory')->default(true);
            $table->enum('extension_approval', ['both', 'pm', 'sup'])->default('both');
            $table->boolean('auto_late_delay')->default(true);

            $table->unsignedTinyInteger('data_review_month')->default(10);
            $table->unsignedTinyInteger('data_review_window_days')->default(30);

            $table->boolean('require_resume')->default(true);
            $table->unsignedTinyInteger('candidate_retention_months')->default(12);
            $table->enum('former_employee_policy', ['interview', 'choose', 'direct'])->default('choose');

            $table->enum('kpi_mode', ['system', 'external'])->default('system');
            $table->json('kpi_weights');

            $table->unsignedInteger('version')->default(1);
            $table->datetimes6();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        });

        $checks = [
            'company_settings_singleton' => 'id = 1',
            'company_settings_name' => 'CHAR_LENGTH(TRIM(company_name)) > 0',
            'company_settings_country' => "BINARY country REGEXP '^[A-Z]{2}$'",
            'company_settings_currency' => "BINARY currency REGEXP '^[A-Z]{3}$'",
            'company_settings_working_days' => "JSON_VALID(working_days) AND JSON_TYPE(working_days) = 'ARRAY' AND JSON_LENGTH(working_days) BETWEEN 1 AND 7",
            'company_settings_two_fa_roles' => "JSON_VALID(two_fa_roles) AND JSON_TYPE(two_fa_roles) = 'ARRAY'",
            'company_settings_session_timeout' => 'session_timeout_minutes IN (15, 30, 60, 480)',
            'company_settings_probation_months' => 'default_probation_months BETWEEN 0 AND 6',
            'company_settings_probation_reminder' => 'probation_reminder_days IN (7, 14, 30)',
            'company_settings_pip_days' => 'pip_default_days IN (30, 60, 90)',
            'company_settings_checkin_every' => 'checkin_every_workdays BETWEEN 1 AND 5',
            'company_settings_review_month' => 'data_review_month BETWEEN 1 AND 12',
            'company_settings_review_window' => 'data_review_window_days IN (14, 30, 45)',
            'company_settings_retention' => 'candidate_retention_months IN (6, 12, 24)',
            'company_settings_kpi_weights' => "JSON_VALID(kpi_weights) AND JSON_TYPE(kpi_weights) = 'OBJECT' AND JSON_LENGTH(kpi_weights) = 7"
                ." AND JSON_CONTAINS_PATH(kpi_weights, 'all', '$.delivery', '$.ontime', '$.delay', '$.checkin', '$.quality', '$.rating', '$.meeting')",
            'company_settings_kpi_weights_sum' => "JSON_EXTRACT(kpi_weights, '$.delivery') + JSON_EXTRACT(kpi_weights, '$.ontime')"
                ." + JSON_EXTRACT(kpi_weights, '$.delay') + JSON_EXTRACT(kpi_weights, '$.checkin')"
                ." + JSON_EXTRACT(kpi_weights, '$.quality') + JSON_EXTRACT(kpi_weights, '$.rating')"
                ." + JSON_EXTRACT(kpi_weights, '$.meeting') = 100",
            'company_settings_version' => 'version >= 1',
        ];

        foreach ($checks as $name => $expression) {
            DB::statement("ALTER TABLE company_settings ADD CONSTRAINT {$name} CHECK ({$expression})");
        }

        // The singleton row always exists; the installer's onboarding renames it.
        DB::table('company_settings')->insert([
            'id' => 1,
            'company_name' => 'My Company',
            'working_days' => json_encode([1, 2, 3, 4, 5]),
            'two_fa_roles' => json_encode(['sysadmin', 'exec', 'hr']),
            'kpi_weights' => json_encode([
                'delivery' => 30, 'ontime' => 20, 'delay' => 15, 'checkin' => 10,
                'quality' => 10, 'rating' => 10, 'meeting' => 5,
            ]),
            'created_at' => now('UTC')->format('Y-m-d H:i:s.u'),
            'updated_at' => now('UTC')->format('Y-m-d H:i:s.u'),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('company_settings');
    }
};
