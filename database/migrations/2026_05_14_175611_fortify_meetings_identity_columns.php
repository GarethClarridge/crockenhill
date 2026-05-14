<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SLUG_FORMAT_CHECK = 'meetings_slug_integrity_check';
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
        // 1. Data Cleanup: Normalize existing data before adding constraints
        DB::table('meetings')->update([
            'slug' => DB::raw('TRIM(slug)'),
            'day' => DB::raw('TRIM(day)'),
            'who' => DB::raw('TRIM(who)'),
            'location' => DB::raw('TRIM(location)'),
            'leaders_phone' => DB::raw('TRIM(leaders_phone)'),
            'leaders_email' => DB::raw('TRIM(leaders_email)'),
        ]);

        // Nullify empty strings for nullable columns
        DB::table('meetings')->where('location', '')->update(['location' => null]);
        DB::table('meetings')->where('leaders_phone', '')->update(['leaders_phone' => null]);
        DB::table('meetings')->where('leaders_email', '')->update(['leaders_email' => null]);

        // Resolve any duplicate slugs before adding unique index
        $this->resolveDuplicateSlugs();

        Schema::table('meetings', function (Blueprint $table) {
            // 2. Add unique index on slug if it doesn't exist
            if (!Schema::hasIndex('meetings', 'meetings_slug_unique')) {
                $table->unique('slug', 'meetings_slug_unique');
            }
        });

        if (DB::getDriverName() === 'mysql') {
            // 3. Add CHECK constraints
            // required fields: trimmed and not empty
            DB::statement(sprintf(
                "ALTER TABLE meetings ADD CONSTRAINT %s CHECK (BINARY slug = TRIM(slug) AND slug != '')",
                self::SLUG_FORMAT_CHECK
            ));
            DB::statement(sprintf(
                "ALTER TABLE meetings ADD CONSTRAINT %s CHECK (BINARY day = TRIM(day) AND day != '')",
                self::DAY_FORMAT_CHECK
            ));
            DB::statement(sprintf(
                "ALTER TABLE meetings ADD CONSTRAINT %s CHECK (BINARY who = TRIM(who) AND who != '')",
                self::WHO_FORMAT_CHECK
            ));

            // nullable fields: trimmed if not null
            DB::statement(sprintf(
                "ALTER TABLE meetings ADD CONSTRAINT %s CHECK (location IS NULL OR BINARY location = TRIM(location))",
                self::LOCATION_FORMAT_CHECK
            ));
            DB::statement(sprintf(
                "ALTER TABLE meetings ADD CONSTRAINT %s CHECK (leaders_phone IS NULL OR BINARY leaders_phone = TRIM(leaders_phone))",
                self::LEADERS_PHONE_FORMAT_CHECK
            ));
            DB::statement(sprintf(
                "ALTER TABLE meetings ADD CONSTRAINT %s CHECK (leaders_email IS NULL OR BINARY leaders_email = TRIM(leaders_email))",
                self::LEADERS_EMAIL_FORMAT_CHECK
            ));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement(sprintf('ALTER TABLE meetings DROP CHECK %s', self::SLUG_FORMAT_CHECK));
            DB::statement(sprintf('ALTER TABLE meetings DROP CHECK %s', self::DAY_FORMAT_CHECK));
            DB::statement(sprintf('ALTER TABLE meetings DROP CHECK %s', self::WHO_FORMAT_CHECK));
            DB::statement(sprintf('ALTER TABLE meetings DROP CHECK %s', self::LOCATION_FORMAT_CHECK));
            DB::statement(sprintf('ALTER TABLE meetings DROP CHECK %s', self::LEADERS_PHONE_FORMAT_CHECK));
            DB::statement(sprintf('ALTER TABLE meetings DROP CHECK %s', self::LEADERS_EMAIL_FORMAT_CHECK));
        }

        Schema::table('meetings', function (Blueprint $table) {
            if (Schema::hasIndex('meetings', 'meetings_slug_unique')) {
                $table->dropUnique('meetings_slug_unique');
            }
        });
    }

    private function resolveDuplicateSlugs(): void
    {
        $duplicates = DB::table('meetings')
            ->select('slug')
            ->groupBy('slug')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $records = DB::table('meetings')
                ->where('slug', $duplicate->slug)
                ->orderBy('id')
                ->get();

            foreach ($records->skip(1) as $index => $record) {
                $newSlug = $duplicate->slug . '-' . ($index + 2);

                $counter = 2;
                while (DB::table('meetings')->where('slug', $newSlug)->exists()) {
                    $counter++;
                    $newSlug = $duplicate->slug . '-' . $counter;
                }

                DB::table('meetings')
                    ->where('id', $record->id)
                    ->update(['slug' => $newSlug]);
            }
        }
    }
};
