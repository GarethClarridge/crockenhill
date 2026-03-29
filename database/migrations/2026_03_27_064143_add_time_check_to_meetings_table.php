<?php

declare(strict_types=1);

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
        // Data Cleanup: Nullify end_time where it violates the logic (end before start)
        // to prevent migration failure on existing data.
        DB::table('meetings')
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->whereColumn('end_time', '<', 'start_time')
            ->update(['end_time' => null]);

        Schema::table('meetings', function (Blueprint $table) {
            // end_time must be after or equal to start_time if both are set
            if (DB::getDriverName() === 'mysql') {
                DB::statement('ALTER TABLE meetings ADD CONSTRAINT meetings_time_check CHECK (end_time >= start_time OR end_time IS NULL OR start_time IS NULL)');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE meetings DROP CHECK meetings_time_check');
        }
    }
};
