<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SERMONS_VIDEO_PATH_CHECK = 'sermons_video_file_path_format_check';

    private const PROCESSING_LOGS_VIDEO_PATH_CHECK = 'media_processing_logs_video_file_path_format_check';

    private const SONG_VIDEOS_VIDEO_PATH_CHECK = 'song_videos_video_file_path_format_check';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // 1. Data Cleanup: Normalize existing data
        if (Schema::hasTable('sermons')) {
            DB::table('sermons')->update([
                'video_file_path' => DB::raw('NULLIF(TRIM(video_file_path), "")'),
            ]);

            DB::statement(sprintf(
                "ALTER TABLE sermons ADD CONSTRAINT %s CHECK (video_file_path IS NULL OR (BINARY video_file_path = TRIM(video_file_path) AND video_file_path != ''))",
                self::SERMONS_VIDEO_PATH_CHECK
            ));
        }

        if (Schema::hasTable('media_processing_logs')) {
            DB::table('media_processing_logs')->update([
                'video_file_path' => DB::raw('NULLIF(TRIM(video_file_path), "")'),
            ]);

            DB::statement(sprintf(
                "ALTER TABLE media_processing_logs ADD CONSTRAINT %s CHECK (video_file_path IS NULL OR (BINARY video_file_path = TRIM(video_file_path) AND video_file_path != ''))",
                self::PROCESSING_LOGS_VIDEO_PATH_CHECK
            ));
        }

        if (Schema::hasTable('song_videos')) {
            DB::table('song_videos')->update([
                'video_file_path' => DB::raw('TRIM(video_file_path)'),
            ]);

            // For song_videos, video_file_path is non-nullable by initial migration.
            // If any ended up empty after trimming, we assign a placeholder to satisfy the constraint.
            DB::table('song_videos')
                ->where('video_file_path', '')
                ->update(['video_file_path' => DB::raw("CONCAT('video_', id)")]);

            DB::statement(sprintf(
                "ALTER TABLE song_videos ADD CONSTRAINT %s CHECK (BINARY video_file_path = TRIM(video_file_path) AND video_file_path != '')",
                self::SONG_VIDEOS_VIDEO_PATH_CHECK
            ));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if (Schema::hasTable('sermons')) {
            DB::statement(sprintf('ALTER TABLE sermons DROP CHECK %s', self::SERMONS_VIDEO_PATH_CHECK));
        }

        if (Schema::hasTable('media_processing_logs')) {
            DB::statement(sprintf('ALTER TABLE media_processing_logs DROP CHECK %s', self::PROCESSING_LOGS_VIDEO_PATH_CHECK));
        }

        if (Schema::hasTable('song_videos')) {
            DB::statement(sprintf('ALTER TABLE song_videos DROP CHECK %s', self::SONG_VIDEOS_VIDEO_PATH_CHECK));
        }
    }
};
