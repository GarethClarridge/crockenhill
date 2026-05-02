<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const GOOGLE_EVENT_ID_FORMAT_CHECK = 'calendar_events_google_event_id_format_check';

    private const TITLE_FORMAT_CHECK = 'calendar_events_title_format_check';

    private const SPEAKER_FORMAT_CHECK = 'calendar_events_speaker_format_check';

    private const LOCATION_FORMAT_CHECK = 'calendar_events_location_format_check';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('calendar_events')) {
            return;
        }

        // 1. Ensure the columns are non-nullable where expected (google_event_id and title)
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->string('google_event_id', 255)->nullable(false)->change();
            $table->string('title', 255)->nullable(false)->change();
        });

        if (DB::getDriverName() === 'mysql') {
            // 2. Add CHECK constraints.
            // google_event_id must be trimmed and not empty
            DB::statement(sprintf(
                "ALTER TABLE calendar_events ADD CONSTRAINT %s CHECK (BINARY google_event_id = TRIM(google_event_id) AND google_event_id != '')",
                self::GOOGLE_EVENT_ID_FORMAT_CHECK
            ));

            // title must be trimmed and not empty
            DB::statement(sprintf(
                "ALTER TABLE calendar_events ADD CONSTRAINT %s CHECK (BINARY title = TRIM(title) AND title != '')",
                self::TITLE_FORMAT_CHECK
            ));

            // speaker must be trimmed if not null
            DB::statement(sprintf(
                'ALTER TABLE calendar_events ADD CONSTRAINT %s CHECK (speaker IS NULL OR BINARY speaker = TRIM(speaker))',
                self::SPEAKER_FORMAT_CHECK
            ));

            // location must be trimmed if not null
            DB::statement(sprintf(
                'ALTER TABLE calendar_events ADD CONSTRAINT %s CHECK (location IS NULL OR BINARY location = TRIM(location))',
                self::LOCATION_FORMAT_CHECK
            ));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement(sprintf('ALTER TABLE calendar_events DROP CHECK %s', self::GOOGLE_EVENT_ID_FORMAT_CHECK));
            DB::statement(sprintf('ALTER TABLE calendar_events DROP CHECK %s', self::TITLE_FORMAT_CHECK));
            DB::statement(sprintf('ALTER TABLE calendar_events DROP CHECK %s', self::SPEAKER_FORMAT_CHECK));
            DB::statement(sprintf('ALTER TABLE calendar_events DROP CHECK %s', self::LOCATION_FORMAT_CHECK));
        }

        Schema::table('calendar_events', function (Blueprint $table) {
            $table->string('google_event_id', 255)->nullable()->change();
            // title was already required by initial migration but we'll leave it as non-nullable
        });
    }
};
