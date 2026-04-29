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
            $table->index('original_filename', 'media_processing_logs_original_filename_index');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(sprintf(
                'ALTER TABLE media_processing_logs ADD CONSTRAINT %s CHECK (sermon_start_time IS NULL OR sermon_end_time IS NULL OR sermon_end_time >= sermon_start_time)',
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
            DB::statement(sprintf('ALTER TABLE media_processing_logs DROP CHECK %s', self::SERMON_TIME_RANGE_CHECK));
            DB::statement(sprintf('ALTER TABLE media_processing_logs DROP CHECK %s', self::ORIGINAL_FILENAME_FORMAT_CHECK));
        }

        Schema::table('media_processing_logs', function (Blueprint $table) {
            $table->dropIndex('media_processing_logs_original_filename_index');
        });
    }
};
