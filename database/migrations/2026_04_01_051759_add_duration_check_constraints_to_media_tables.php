<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MEDIA_PROCESSING_LOG_DURATION_CHECK = 'media_processing_logs_duration_check';

    private const SONG_VIDEO_DURATION_CHECK = 'song_videos_duration_check';

    private const MEDIA_PROCESSING_LOG_TIMING_CHECK = 'media_processing_logs_timing_check';

    private const LIVESTREAM_SEGMENT_TIMING_CHECK = 'livestream_segments_timing_check';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // 1. Duration checks
            if (Schema::hasTable('media_processing_logs')) {
                $this->addConstraintIfNotExists('media_processing_logs', self::MEDIA_PROCESSING_LOG_DURATION_CHECK, 'duration >= 0 OR duration IS NULL');
            }

            if (Schema::hasTable('song_videos')) {
                $this->addConstraintIfNotExists('song_videos', self::SONG_VIDEO_DURATION_CHECK, 'duration >= 0 OR duration IS NULL');
            }

            // 2. Timing invariants (start <= end)
            if (Schema::hasTable('media_processing_logs')) {
                $this->addConstraintIfNotExists('media_processing_logs', self::MEDIA_PROCESSING_LOG_TIMING_CHECK, 'sermon_start_time >= 0 AND (sermon_end_time >= sermon_start_time OR sermon_end_time IS NULL OR sermon_start_time IS NULL)');
            }

            if (Schema::hasTable('livestream_segments')) {
                $this->addConstraintIfNotExists('livestream_segments', self::LIVESTREAM_SEGMENT_TIMING_CHECK, 'start_time >= 0 AND end_time >= start_time AND duration >= 0');
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            if (Schema::hasTable('media_processing_logs')) {
                $this->dropConstraintIfExists('media_processing_logs', self::MEDIA_PROCESSING_LOG_DURATION_CHECK);
                $this->dropConstraintIfExists('media_processing_logs', self::MEDIA_PROCESSING_LOG_TIMING_CHECK);
            }

            if (Schema::hasTable('song_videos')) {
                $this->dropConstraintIfExists('song_videos', self::SONG_VIDEO_DURATION_CHECK);
            }

            if (Schema::hasTable('livestream_segments')) {
                $this->dropConstraintIfExists('livestream_segments', self::LIVESTREAM_SEGMENT_TIMING_CHECK);
            }
        }
    }

    private function addConstraintIfNotExists(string $table, string $constraint, string $check): void
    {
        if (! $this->constraintExists($table, $constraint)) {
            DB::statement(sprintf('ALTER TABLE %s ADD CONSTRAINT %s CHECK (%s)', $table, $constraint, $check));
        }
    }

    private function dropConstraintIfExists(string $table, string $constraint): void
    {
        if ($this->constraintExists($table, $constraint)) {
            DB::statement(sprintf('ALTER TABLE %s DROP CHECK %s', $table, $constraint));
        }
    }

    private function constraintExists(string $table, string $constraint): bool
    {
        return DB::table('information_schema.table_constraints')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('constraint_name', $constraint)
            ->exists();
    }
};
