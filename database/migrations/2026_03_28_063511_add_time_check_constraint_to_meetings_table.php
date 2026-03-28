<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CONSTRAINT_NAME = 'meetings_time_check';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Backfill existing invalid rows by setting end_time to start_time
        // where end_time is earlier than start_time.
        DB::table('meetings')
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->whereRaw('end_time < start_time')
            ->update([
                'end_time' => DB::raw('start_time'),
            ]);

        // MySQL 8.0.16+ supports CHECK constraints.
        // end_time must be after or equal to start_time when both are present.
        DB::statement(sprintf(
            'ALTER TABLE meetings ADD CONSTRAINT %s CHECK (end_time >= start_time OR start_time IS NULL OR end_time IS NULL)',
            self::CONSTRAINT_NAME
        ));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE meetings DROP CHECK %s',
            self::CONSTRAINT_NAME
        ));
    }
};
