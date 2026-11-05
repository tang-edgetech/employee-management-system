<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail (ADR 0001 §5.14, amended 2026-11-05). Rows are written in
 * the same transaction as the change they describe; triggers stop anyone, the app
 * included, from editing or deleting them. No foreign keys, so rows outlive the
 * users and records they mention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->dateTime('occurred_at', 6);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->binary('ip', 16, fixed: false)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->char('request_id', 26)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->string('action', 64);
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('changes')->nullable();

            $table->index(['subject_type', 'subject_id', 'occurred_at']);
            $table->index(['actor_user_id', 'occurred_at']);
            $table->index('occurred_at');
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_log_block_update BEFORE UPDATE ON audit_log
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only: UPDATE is not allowed'
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_log_block_delete BEFORE DELETE ON audit_log
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only: DELETE is not allowed'
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_log_block_update');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_log_block_delete');
        Schema::dropIfExists('audit_log');
    }
};
