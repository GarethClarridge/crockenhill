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

        // 1. Allow `day` to be NULL for one-off events whose schedule is announced
        // separately (e.g. "Carols in the Chequers", "Christianity Explored"). The
        // column is still validated for format when populated; see the CHECK below.
        DB::statement('ALTER TABLE meetings MODIFY day VARCHAR(75) NULL');

        // 2. Data Cleanup: Normalize existing data where possible (trimming and lowercasing)
        // This is safe as it doesn't change the meaning of the data, only its format.
        // Empty/whitespace-only `day` values become NULL — they represent events with no
        // scheduled day-of-week, distinct from rows that carry a meaningful descriptor.
        // For required fields (who) we still let the migration fail loudly if data
        // integrity is already compromised, per Warden standards.
        DB::table('meetings')->update([
            'day' => DB::raw('NULLIF(TRIM(day), "")'),
            'who' => DB::raw('TRIM(who)'),
            'location' => DB::raw('NULLIF(TRIM(location), "")'),
            'leaders_phone' => DB::raw('NULLIF(TRIM(leaders_phone), "")'),
            'leaders_email' => DB::raw('LOWER(NULLIF(TRIM(leaders_email), ""))'),
        ]);

        // 3. Add CHECK constraints (MySQL 8.0.16+)
        // BINARY is used to ensure exact character-for-character match for the trim check.
        DB::statement(sprintf(
            "ALTER TABLE meetings ADD CONSTRAINT %s CHECK (day IS NULL OR (BINARY day = TRIM(day) AND day != ''))",
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

        // We deliberately do NOT restore `day` to NOT NULL here. Doing so would require
        // fabricating values for any rows where day is currently NULL, which is destructive.
        // Leaving the column nullable on rollback is the safer one-way change.
    }
};
