<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sermons', function (Blueprint $table) {
            // Drop index first to avoid SQLite issues
            if (DB::getDriverName() === 'sqlite') {
                $table->dropIndex('sermons_source_type_index');
            }
            // Modify the existing source_type enum to include video_upload
            $table->dropColumn('source_type');
        });

        Schema::table('sermons', function (Blueprint $table) {
            $table->enum('source_type', ['manual', 'audio_upload', 'livestream', 'video_upload'])
                ->default('manual')
                ->after('video_file_path');
            $table->index('source_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sermons', function (Blueprint $table) {
            $table->dropIndex(['source_type']);
            $table->dropColumn('source_type');
        });

        Schema::table('sermons', function (Blueprint $table) {
            $table->enum('source_type', ['manual', 'audio_upload', 'livestream'])
                ->default('manual')
                ->after('video_file_path');
            $table->index('source_type');
        });
    }
};
