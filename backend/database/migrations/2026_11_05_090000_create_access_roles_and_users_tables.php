<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Access roles and user accounts (plan 01 §3.2-3.3). Permissions and the role
 * matrix arrive in M4; employees in M4, when employee_id gets its foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_roles', function (Blueprint $table) {
            $table->id();
            $table->string('key', 20)->charset('ascii')->collation('ascii_bin')->unique();
            $table->string('name', 60);
            $table->string('note', 160)->nullable();
            $table->boolean('locked')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->datetimes6();
        });

        // The five roles of brief §5.1 exist on every install; their keys are fixed in code (RoleKey).
        $now = now('UTC')->format('Y-m-d H:i:s.u');
        DB::table('access_roles')->insert([
            ['key' => 'sysadmin', 'name' => 'System Admin', 'note' => 'Vendor support team', 'locked' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'exec', 'name' => 'C-Level', 'note' => 'Company-wide view & edit', 'locked' => false, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'hr', 'name' => 'HR Manager', 'note' => 'Full people & hiring', 'locked' => false, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'lead', 'name' => 'Team Lead', 'note' => 'Own team, projects, interviews', 'locked' => false, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'staff', 'name' => 'Employee', 'note' => 'Self-service; seniors see direct reports', 'locked' => false, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->string('name', 150);
            $table->string('email', 190)->unique();
            $table->string('password');
            $table->foreignId('role_id')->constrained('access_roles')->restrictOnDelete();
            // FK to employees is added with the employees table (M4). System Admins have none.
            $table->unsignedBigInteger('employee_id')->nullable()->unique();
            $table->enum('status', ['invited', 'active', 'disabled'])->default('invited');

            // Fortify's column names. Secret and recovery codes are encrypted by Fortify.
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->dateTime('two_factor_confirmed_at', 6)->nullable();

            $table->enum('ui_layout', ['indigo', 'pandan', 'blueprint', 'plum', 'kopi'])->default('indigo');
            $table->dateTime('last_login_at', 6)->nullable();
            $table->dateTime('password_changed_at', 6)->nullable();
            $table->rememberToken();
            $table->unsignedInteger('version')->default(1);
            $table->datetimes6();
        });

        DB::statement('ALTER TABLE users ADD CONSTRAINT users_email_lowercase CHECK (BINARY email = BINARY LOWER(email))');

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email', 190)->primary();
            $table->string('token');
            $table->dateTime('created_at', 6)->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
        Schema::dropIfExists('access_roles');
    }
};
