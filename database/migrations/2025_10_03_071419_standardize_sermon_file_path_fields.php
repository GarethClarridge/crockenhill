<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Standardize file path field naming across the sermons table to match
     * MediaProcessingLog conventions (all file paths end with _file_path).
     */
    public function up(): void
    {
        Schema::table('sermons', function (Blueprint $table) {
            // Rename filename → audio_file_path
            $table->renameColumn('filename', 'audio_file_path');

            // Rename transcript_path → transcript_file_path
            $table->renameColumn('transcript_path', 'transcript_file_path');

            // Rename thumbnail_path → thumbnail_file_path
            $table->renameColumn('thumbnail_path', 'thumbnail_file_path');

            // video_file_path already has correct naming
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sermons', function (Blueprint $table) {
            // Reverse the renames
            $table->renameColumn('audio_file_path', 'filename');
            $table->renameColumn('transcript_file_path', 'transcript_path');
            $table->renameColumn('thumbnail_file_path', 'thumbnail_path');
        });
    }
};
