<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MEDIA_LOG_FILE_SIZE_CHECK = 'media_processing_logs_file_size_check';

    private const MEDIA_LOG_VISUAL_SAMPLES_CHECK = 'media_processing_logs_visual_sample_count_check';

    private const MEDIA_LOG_VISUAL_TIME_CHECK = 'media_processing_logs_visual_processing_time_check';

    private const SEGMENT_VISUAL_SAMPLES_CHECK = 'livestream_segments_visual_sample_count_check';

    private const SEGMENT_VISUAL_CONFIDENCE_CHECK = 'livestream_segments_visual_confidence_check';

    private const SEGMENT_ORDER_CHECK = 'livestream_segments_segment_order_check';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // 1. Data Cleanup for MediaProcessingLog
        if (Schema::hasTable('media_processing_logs')) {
            DB::table('media_processing_logs')
                ->where('file_size', '<', 0)
                ->update(['file_size' => 0]);

            DB::table('media_processing_logs')
                ->where('visual_sample_count', '<', 0)
                ->update(['visual_sample_count' => 0]);

            DB::table('media_processing_logs')
                ->where('visual_processing_time', '<', 0)
                ->update(['visual_processing_time' => 0]);

            // Add constraints
            $this->addConstraint('media_processing_logs', self::MEDIA_LOG_FILE_SIZE_CHECK, 'file_size >= 0 OR file_size IS NULL');
            $this->addConstraint('media_processing_logs', self::MEDIA_LOG_VISUAL_SAMPLES_CHECK, 'visual_sample_count >= 0 OR visual_sample_count IS NULL');
            $this->addConstraint('media_processing_logs', self::MEDIA_LOG_VISUAL_TIME_CHECK, 'visual_processing_time >= 0 OR visual_processing_time IS NULL');
        }

        // 2. Data Cleanup for LivestreamSegment
        if (Schema::hasTable('livestream_segments')) {
            DB::table('livestream_segments')
                ->where('visual_sample_count', '<', 0)
                ->update(['visual_sample_count' => 0]);

            DB::table('livestream_segments')
                ->where('visual_confidence', '<', 0)
                ->update(['visual_confidence' => 0]);

            DB::table('livestream_segments')
                ->where('visual_confidence', '>', 1)
                ->update(['visual_confidence' => 1]);

            DB::table('livestream_segments')
                ->where('segment_order', '<', 0)
                ->update(['segment_order' => 0]);

            // Add constraints
            $this->addConstraint('livestream_segments', self::SEGMENT_VISUAL_SAMPLES_CHECK, 'visual_sample_count >= 0 OR visual_sample_count IS NULL');
            $this->addConstraint('livestream_segments', self::SEGMENT_VISUAL_CONFIDENCE_CHECK, '(visual_confidence >= 0 AND visual_confidence <= 1) OR visual_confidence IS NULL');
            $this->addConstraint('livestream_segments', self::SEGMENT_ORDER_CHECK, 'segment_order >= 0 OR segment_order IS NULL');
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

        $this->dropConstraint('media_processing_logs', self::MEDIA_LOG_FILE_SIZE_CHECK);
        $this->dropConstraint('media_processing_logs', self::MEDIA_LOG_VISUAL_SAMPLES_CHECK);
        $this->dropConstraint('media_processing_logs', self::MEDIA_LOG_VISUAL_TIME_CHECK);

        $this->dropConstraint('livestream_segments', self::SEGMENT_VISUAL_SAMPLES_CHECK);
        $this->dropConstraint('livestream_segments', self::SEGMENT_VISUAL_CONFIDENCE_CHECK);
        $this->dropConstraint('livestream_segments', self::SEGMENT_ORDER_CHECK);
    }

    private function addConstraint(string $table, string $constraint, string $check): void
    {
        if (! $this->constraintExists($table, $constraint)) {
            DB::statement(sprintf('ALTER TABLE %s ADD CONSTRAINT %s CHECK (%s)', $table, $constraint, $check));
        }
    }

    private function dropConstraint(string $table, string $constraint): void
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
