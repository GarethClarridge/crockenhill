<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DISPLAY_NAME_FORMAT_CHECK = 'song_authors_display_name_format_check';

    private const FIRST_NAME_FORMAT_CHECK = 'song_authors_first_name_format_check';

    private const LAST_NAME_FORMAT_CHECK = 'song_authors_last_name_format_check';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('song_authors')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            // Add CHECK constraints
            // BINARY ensures exact character-for-character match for the trim check.
            DB::statement(sprintf(
                "ALTER TABLE song_authors ADD CONSTRAINT %s CHECK (BINARY display_name = TRIM(display_name) AND display_name != '')",
                self::DISPLAY_NAME_FORMAT_CHECK
            ));

            DB::statement(sprintf(
                'ALTER TABLE song_authors ADD CONSTRAINT %s CHECK (first_name IS NULL OR BINARY first_name = TRIM(first_name))',
                self::FIRST_NAME_FORMAT_CHECK
            ));

            DB::statement(sprintf(
                'ALTER TABLE song_authors ADD CONSTRAINT %s CHECK (last_name IS NULL OR BINARY last_name = TRIM(last_name))',
                self::LAST_NAME_FORMAT_CHECK
            ));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('song_authors')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement(sprintf('ALTER TABLE song_authors DROP CHECK %s', self::DISPLAY_NAME_FORMAT_CHECK));
            DB::statement(sprintf('ALTER TABLE song_authors DROP CHECK %s', self::FIRST_NAME_FORMAT_CHECK));
            DB::statement(sprintf('ALTER TABLE song_authors DROP CHECK %s', self::LAST_NAME_FORMAT_CHECK));
        }
    }
};
