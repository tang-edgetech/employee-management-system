<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Session timeout options after the mockup review (plan 01 §9.2):
 * 15, 30, 60, 120 or 240 minutes. 480 (8 hours) is no longer offered.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('company_settings')->where('session_timeout_minutes', 480)->update(['session_timeout_minutes' => 240]);
        DB::statement('ALTER TABLE company_settings DROP CONSTRAINT company_settings_session_timeout');
        DB::statement('ALTER TABLE company_settings ADD CONSTRAINT company_settings_session_timeout CHECK (session_timeout_minutes IN (15, 30, 60, 120, 240))');
    }

    public function down(): void
    {
        DB::table('company_settings')->whereIn('session_timeout_minutes', [120, 240])->update(['session_timeout_minutes' => 60]);
        DB::statement('ALTER TABLE company_settings DROP CONSTRAINT company_settings_session_timeout');
        DB::statement('ALTER TABLE company_settings ADD CONSTRAINT company_settings_session_timeout CHECK (session_timeout_minutes IN (15, 30, 60, 480))');
    }
};
