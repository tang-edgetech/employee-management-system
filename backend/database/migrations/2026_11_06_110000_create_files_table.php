<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Uploaded files (plan 01 §3.5, F6). A row is created when an upload URL is
 * issued; confirmed_at is set once the bytes are verified; the file is usable only
 * when scan_status = 'clean'. The same content uploaded twice is stored twice but
 * shares a sha256, so duplicates can be found later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->enum('purpose', ['photo', 'resume', 'evidence', 'logo', 'import']);
            $table->string('disk', 32)->charset('ascii')->collation('ascii_bin');
            $table->string('storage_key', 255)->charset('ascii')->collation('ascii_bin')->unique();
            $table->string('original_name', 255);
            $table->string('declared_mime', 127);
            $table->string('mime', 127)->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64)->charset('ascii')->collation('ascii_bin')->nullable()->index();
            $table->enum('scan_status', ['pending', 'clean', 'infected'])->default('pending');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('confirmed_at', 6)->nullable();
            $table->dateTime('scanned_at', 6)->nullable();
            $table->datetimes6();

            $table->index(['confirmed_at', 'created_at']);
        });

        DB::statement('ALTER TABLE files ADD CONSTRAINT files_size_positive CHECK (size_bytes > 0)');
        DB::statement("ALTER TABLE files ADD CONSTRAINT files_sha256_hex CHECK (sha256 IS NULL OR sha256 REGEXP '^[0-9a-f]{64}$')");
        DB::statement('ALTER TABLE files ADD CONSTRAINT files_clean_needs_confirm CHECK (scan_status = \'pending\' OR confirmed_at IS NOT NULL)');

        Schema::table('company_settings', function (Blueprint $table) {
            $table->foreign('logo_file_id')->references('id')->on('files')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropForeign(['logo_file_id']);
        });
        Schema::dropIfExists('files');
    }
};
