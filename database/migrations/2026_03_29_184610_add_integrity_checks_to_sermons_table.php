<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PREACHER_CONFIDENCE_CHECK = 'sermons_preacher_confidence_check';

    private const TIMING_INVARIANTS_CHECK = 'sermons_timing_invariants_check';

    private const DURATION_CHECK = 'sermons_duration_check';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('sermons')) {
            return;
        }

        // Data Cleanup: Normalize invalid data before adding constraints
        DB::table('sermons')
            ->where('preacher_confidence', '<', 0)
            ->update(['preacher_confidence' => 0]);

        DB::table('sermons')
            ->where('preacher_confidence', '>', 1)
            ->update(['preacher_confidence' => 1]);

        DB::table('sermons')
            ->whereNotNull('segment_start_time')
            ->whereNotNull('segment_end_time')
            ->whereColumn('segment_end_time', '<', 'segment_start_time')
            ->update(['segment_end_time' => DB::raw('segment_start_time')]);

        DB::table('sermons')
            ->where('segment_start_time', '<', 0)
            ->update(['segment_start_time' => 0]);

        DB::table('sermons')
            ->where('segment_end_time', '<', 0)
            ->update(['segment_end_time' => 0]);

        DB::table('sermons')
            ->where('duration', '<', 0)
            ->update(['duration' => 0]);

        if (DB::getDriverName() === 'mysql') {
            DB::statement(sprintf(
                'ALTER TABLE sermons ADD CONSTRAINT %s CHECK (preacher_confidence >= 0 AND preacher_confidence <= 1)',
                self::PREACHER_CONFIDENCE_CHECK
            ));

            DB::statement(sprintf(
                'ALTER TABLE sermons ADD CONSTRAINT %s CHECK (segment_start_time >= 0 AND (segment_end_time >= segment_start_time OR segment_end_time IS NULL OR segment_start_time IS NULL))',
                self::TIMING_INVARIANTS_CHECK
            ));

            DB::statement(sprintf(
                'ALTER TABLE sermons ADD CONSTRAINT %s CHECK (duration >= 0 OR duration IS NULL)',
                self::DURATION_CHECK
            ));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement(sprintf('ALTER TABLE sermons DROP CHECK %s', self::PREACHER_CONFIDENCE_CHECK));
            DB::statement(sprintf('ALTER TABLE sermons DROP CHECK %s', self::TIMING_INVARIANTS_CHECK));
            DB::statement(sprintf('ALTER TABLE sermons DROP CHECK %s', self::DURATION_CHECK));
        }
    }
};
