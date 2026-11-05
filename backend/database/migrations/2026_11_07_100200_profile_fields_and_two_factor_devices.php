<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 01 §9.2:
 *  - users get a display name (others see its first letter), a personal picture shown
 *    only to themselves, and their interface language; the theme column goes (one design).
 *  - two-factor authentication moves from one secret per user to up to two devices.
 *    Recovery codes stay on the user.
 *  - files get an "avatar" purpose for personal pictures.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('display_name', 60)->nullable()->after('name');
            $table->foreignId('avatar_file_id')->nullable()->after('display_name')->constrained('files')->nullOnDelete();
            $table->enum('locale', ['en', 'zh-CN'])->default('en')->after('status');
            $table->dropColumn(['ui_layout', 'two_factor_secret', 'two_factor_confirmed_at']);
        });

        Schema::create('two_factor_devices', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 60);
            $table->text('secret'); // encrypted TOTP secret
            $table->dateTime('confirmed_at', 6)->nullable();
            $table->dateTime('last_used_at', 6)->nullable();
            $table->datetimes6();
            $table->index(['user_id', 'confirmed_at']);
        });

        DB::statement("ALTER TABLE files MODIFY purpose ENUM('photo','avatar','resume','evidence','logo','import') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM files WHERE purpose = 'avatar'");
        DB::statement("ALTER TABLE files MODIFY purpose ENUM('photo','resume','evidence','logo','import') NOT NULL");
        Schema::dropIfExists('two_factor_devices');
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['avatar_file_id']);
            $table->dropColumn(['display_name', 'avatar_file_id', 'locale']);
            $table->enum('ui_layout', ['indigo', 'pandan', 'blueprint', 'plum', 'kopi'])->default('indigo');
            $table->text('two_factor_secret')->nullable();
            $table->dateTime('two_factor_confirmed_at', 6)->nullable();
        });
    }
};
