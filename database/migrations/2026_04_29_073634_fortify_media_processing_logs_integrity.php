<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SERMON_TIME_RANGE_CHECK = 'media_processing_logs_sermon_time_range_check';

    private const ORIGINAL_FILENAME_FORMAT_CHECK = 'media_processing_logs_original_filename_format_check';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('media_processing_logs', function (Blueprint $table) {
            if (! $this->indexExists('media_processing_logs', 'media_processing_logs_original_filename_index')) {
                $table->index('original_filename', 'media_processing_logs_original_filename_index');
            }
        });

        if (DB::getDriverName() === 'mysql') {
            // 1. Data Cleanup
            // Fix original_filename: trim and ensure not empty
            DB::table('media_processing_logs')->update([
                'original_filename' => DB::raw('TRIM(original_filename)'),
            ]);

            // Ensure no empty original_filenames exist before adding constraint
            DB::table('media_processing_logs')
                ->where('original_filename', '')
                ->update(['original_filename' => DB::raw("CONCAT('file_', id)")]);

            // Ensure start and end times are non-negative if present.
            DB::table('media_processing_logs')
                ->where('sermon_start_time', '<', 0)
                ->update(['sermon_start_time' => 0]);

            DB::table('media_processing_logs')
                ->where('sermon_end_time', '<', 0)
                ->update(['sermon_end_time' => 0]);

            // Fix any cases where end_time is before start_time by aligning them.
            DB::table('media_processing_logs')
                ->whereNotNull('sermon_start_time')
                ->whereNotNull('sermon_end_time')
                ->whereRaw('sermon_end_time < sermon_start_time')
                ->update(['sermon_end_time' => DB::raw('sermon_start_time')]);

            // 2. Add CHECK constraints
            // Surfacing any remaining data integrity issues by not swallowing QueryException.
            DB::statement(sprintf(
                'ALTER TABLE media_processing_logs ADD CONSTRAINT %s CHECK (sermon_start_time IS NULL OR sermon_end_time IS NULL OR (sermon_start_time >= 0 AND sermon_end_time >= 0 AND sermon_end_time >= sermon_start_time))',
                self::SERMON_TIME_RANGE_CHECK
            ));

            DB::statement(sprintf(
                "ALTER TABLE media_processing_logs ADD CONSTRAINT %s CHECK (BINARY original_filename = TRIM(original_filename) AND original_filename != '')",
                self::ORIGINAL_FILENAME_FORMAT_CHECK
            ));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            try {
                DB::statement(sprintf('ALTER TABLE media_processing_logs DROP CHECK %s', self::SERMON_TIME_RANGE_CHECK));
            } catch (\Illuminate\Database\QueryException) {
                // Ignore if it doesn't exist
            }

            try {
                DB::statement(sprintf('ALTER TABLE media_processing_logs DROP CHECK %s', self::ORIGINAL_FILENAME_FORMAT_CHECK));
            } catch (\Illuminate\Database\QueryException) {
                // Ignore if it doesn't exist
            }
        }

        Schema::table('media_processing_logs', function (Blueprint $table) {
            if ($this->indexExists('media_processing_logs', 'media_processing_logs_original_filename_index')) {
                $table->dropIndex('media_processing_logs_original_filename_index');
            }
        });
    }

    /**
     * Check if an index exists on a table
     */
    private function indexExists(string $table, string $indexName): bool
    {
        return Schema::hasIndex($table, $indexName);
    }
};
