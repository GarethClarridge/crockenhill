<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DAY_FORMAT_CHECK = 'meetings_day_format_check';

    private const WHO_FORMAT_CHECK = 'meetings_who_format_check';

    private const LOCATION_FORMAT_CHECK = 'meetings_location_format_check';

    private const LEADERS_PHONE_FORMAT_CHECK = 'meetings_leaders_phone_format_check';

    private const LEADERS_EMAIL_FORMAT_CHECK = 'meetings_leaders_email_format_check';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('meetings')) {
            return;
        }

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
        DB::statement(sprintf(
            "ALTER TABLE meetings ADD CONSTRAINT %s CHECK (BINARY day = TRIM(day) AND day != '')",
            self::DAY_FORMAT_CHECK
        ));

        DB::statement(sprintf(
            "ALTER TABLE meetings ADD CONSTRAINT %s CHECK (BINARY who = TRIM(who) AND who != '')",
            self::WHO_FORMAT_CHECK
        ));

        DB::statement(sprintf(
            "ALTER TABLE meetings ADD CONSTRAINT %s CHECK (location IS NULL OR (BINARY location = TRIM(location) AND location != ''))",
            self::LOCATION_FORMAT_CHECK
        ));

        DB::statement(sprintf(
            "ALTER TABLE meetings ADD CONSTRAINT %s CHECK (leaders_phone IS NULL OR (BINARY leaders_phone = TRIM(leaders_phone) AND leaders_phone != ''))",
            self::LEADERS_PHONE_FORMAT_CHECK
        ));

        DB::statement(sprintf(
            "ALTER TABLE meetings ADD CONSTRAINT %s CHECK (leaders_email IS NULL OR (BINARY leaders_email = LOWER(TRIM(leaders_email)) AND leaders_email != ''))",
            self::LEADERS_EMAIL_FORMAT_CHECK
        ));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('meetings')) {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $constraints = [
            self::DAY_FORMAT_CHECK,
            self::WHO_FORMAT_CHECK,
            self::LOCATION_FORMAT_CHECK,
            self::LEADERS_PHONE_FORMAT_CHECK,
            self::LEADERS_EMAIL_FORMAT_CHECK,
        ];

        foreach ($constraints as $constraint) {
            $constraintExists = DB::select("
                SELECT CONSTRAINT_NAME
                FROM information_schema.TABLE_CONSTRAINTS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'meetings'
                AND CONSTRAINT_NAME = ?
            ", [$constraint]);

            if (! empty($constraintExists)) {
                DB::statement(sprintf('ALTER TABLE meetings DROP CHECK %s', $constraint));
            }
        }
    }
};
