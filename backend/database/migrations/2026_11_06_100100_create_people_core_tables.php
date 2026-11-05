<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The people core (plan 01 §3.4, decision D1): enough for reporting chains, team
 * leads and the demo logins. The People module adds the remaining columns
 * (designation, department, identity, salary...) in later migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->string('employee_no', 20)->unique();
            $table->string('full_name', 150);
            $table->string('work_email', 190)->unique();
            $table->foreignId('superior_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->enum('status', [
                'Permanent', 'Probation', 'Promotion Probation', 'PIP', 'Contract', 'Internship', 'Resigned', 'Terminated',
            ]);
            $table->date('join_date');
            $table->date('left_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->datetimes6();
            $table->dateTime('deleted_at', 6)->nullable();

            // superior_id already has an index from its foreign key; it drives the reporting-chain CTE.
        });

        // MariaDB can't put the auto-increment `id` in a CHECK, so a trigger guards the
        // reporting chain instead, and catches whole loops, not just self-reference
        // (ADR 0001 §5.12). A new row has no subordinates, so inserts only need the self check.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER employees_superior_no_loop_insert BEFORE INSERT ON employees FOR EACH ROW
            BEGIN
                IF NEW.superior_id IS NOT NULL AND NEW.id <> 0 AND NEW.superior_id = NEW.id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'REPORTING_LOOP: an employee cannot report to themselves';
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER employees_superior_no_loop_update BEFORE UPDATE ON employees FOR EACH ROW
            BEGIN
                IF NEW.superior_id IS NOT NULL AND NOT (NEW.superior_id <=> OLD.superior_id) THEN
                    IF NEW.superior_id = NEW.id OR EXISTS (
                        WITH RECURSIVE chain (id) AS (
                            SELECT NEW.superior_id
                            UNION
                            SELECT s.superior_id FROM employees s JOIN chain c ON s.id = c.id WHERE s.superior_id IS NOT NULL
                        )
                        SELECT 1 FROM chain WHERE chain.id = NEW.id
                    ) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'REPORTING_LOOP: this reporting line would form a loop';
                    END IF;
                END IF;
            END
            SQL);

        DB::statement('ALTER TABLE employees ADD CONSTRAINT employees_email_lowercase CHECK (BINARY work_email = BINARY LOWER(work_email))');
        DB::statement('ALTER TABLE employees ADD CONSTRAINT employees_left_after_join CHECK (left_at IS NULL OR left_at >= join_date)');

        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->string('name', 100)->unique();
            $table->foreignId('lead_employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->datetimes6();
        });

        Schema::create('team_members', function (Blueprint $table) {
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->primary(['team_id', 'employee_id']);
            $table->index('employee_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
        });
        Schema::dropIfExists('team_members');
        Schema::dropIfExists('teams');
        DB::unprepared('DROP TRIGGER IF EXISTS employees_superior_no_loop_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS employees_superior_no_loop_update');
        Schema::dropIfExists('employees');
    }
};
