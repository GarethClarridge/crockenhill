<?php

declare(strict_types=1);

use App\Enums\CalendarEventStatus;
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
        $isSqlite = DB::getDriverName() === 'sqlite';

        Schema::table('calendar_events', function (Blueprint $table) use ($isSqlite) {
            if ($isSqlite) {
                $table->string('status', 255)->default('confirmed')->change();
            } else {
                $table->enum('status', CalendarEventStatus::values())
                    ->default(CalendarEventStatus::CONFIRMED->value)
                    ->change();

                // MySQL 8.0.16+ supports CHECK constraints.
                // end_datetime must be after or equal to start_datetime
                DB::statement('ALTER TABLE calendar_events ADD CONSTRAINT calendar_events_timing_check CHECK (end_datetime >= start_datetime)');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $isSqlite = DB::getDriverName() === 'sqlite';

        Schema::table('calendar_events', function (Blueprint $table) use ($isSqlite) {
            if (! $isSqlite) {
                DB::statement('ALTER TABLE calendar_events DROP CONSTRAINT calendar_events_timing_check');
            }

            $table->string('status', 255)->default('confirmed')->change();
        });
    }
};
