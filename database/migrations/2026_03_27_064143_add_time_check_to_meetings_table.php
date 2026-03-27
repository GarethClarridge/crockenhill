<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // end_time must be after or equal to start_time if both are set
            DB::statement('ALTER TABLE meetings ADD CONSTRAINT meetings_time_check CHECK (end_time >= start_time OR end_time IS NULL OR start_time IS NULL)');
        }
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
