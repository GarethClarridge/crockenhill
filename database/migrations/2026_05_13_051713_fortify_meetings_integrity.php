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
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // 1. Data Cleanup: Trim existing data and lowercase email
        DB::table('meetings')->update([
            'day' => DB::raw('TRIM(day)'),
            'who' => DB::raw('TRIM(who)'),
            'location' => DB::raw('NULLIF(TRIM(location), "")'),
            'leaders_phone' => DB::raw('NULLIF(TRIM(leaders_phone), "")'),
            'leaders_email' => DB::raw('LOWER(NULLIF(TRIM(leaders_email), ""))'),
        ]);

        // 2. Ensure no empty required fields exist before adding constraints
        DB::table('meetings')
            ->where('day', '')
            ->update(['day' => 'Unknown Day']);

        DB::table('meetings')
            ->where('who', '')
            ->update(['who' => 'Unknown']);

        // 3. Add CHECK constraints
        // BINARY is used to ensure exact character-for-character match for the trim check.
        DB::statement("ALTER TABLE meetings ADD CONSTRAINT meetings_day_format_check CHECK (BINARY day = TRIM(day) AND day != '')");
        DB::statement("ALTER TABLE meetings ADD CONSTRAINT meetings_who_format_check CHECK (BINARY who = TRIM(who) AND who != '')");
        DB::statement("ALTER TABLE meetings ADD CONSTRAINT meetings_location_format_check CHECK (location IS NULL OR (BINARY location = TRIM(location) AND location != ''))");
        DB::statement("ALTER TABLE meetings ADD CONSTRAINT meetings_leaders_phone_format_check CHECK (leaders_phone IS NULL OR (BINARY leaders_phone = TRIM(leaders_phone) AND leaders_phone != ''))");
        DB::statement("ALTER TABLE meetings ADD CONSTRAINT meetings_leaders_email_format_check CHECK (leaders_email IS NULL OR (BINARY leaders_email = LOWER(TRIM(leaders_email)) AND leaders_email != ''))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE meetings DROP CHECK meetings_day_format_check');
        DB::statement('ALTER TABLE meetings DROP CHECK meetings_who_format_check');
        DB::statement('ALTER TABLE meetings DROP CHECK meetings_location_format_check');
        DB::statement('ALTER TABLE meetings DROP CHECK meetings_leaders_phone_format_check');
        DB::statement('ALTER TABLE meetings DROP CHECK meetings_leaders_email_format_check');
    }
};
