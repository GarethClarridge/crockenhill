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

    public function up(): void
    {
        if (! Schema::hasTable('song_authors')) {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Data Cleanup: normalise existing data before applying constraints.
        DB::table('song_authors')->update([
            'display_name' => DB::raw('TRIM(display_name)'),
            'first_name' => DB::raw("NULLIF(TRIM(first_name), '')"),
            'last_name' => DB::raw("NULLIF(TRIM(last_name), '')"),
        ]);

        DB::table('song_authors')
            ->where('display_name', '')
            ->update(['display_name' => DB::raw("CONCAT('Author ', id)")]);

        DB::statement(sprintf(
            "ALTER TABLE song_authors ADD CONSTRAINT %s CHECK (BINARY display_name = TRIM(display_name) AND display_name != '')",
            self::DISPLAY_NAME_FORMAT_CHECK
        ));

        DB::statement(sprintf(
            "ALTER TABLE song_authors ADD CONSTRAINT %s CHECK (first_name IS NULL OR (BINARY first_name = TRIM(first_name) AND first_name != ''))",
            self::FIRST_NAME_FORMAT_CHECK
        ));

        DB::statement(sprintf(
            "ALTER TABLE song_authors ADD CONSTRAINT %s CHECK (last_name IS NULL OR (BINARY last_name = TRIM(last_name) AND last_name != ''))",
            self::LAST_NAME_FORMAT_CHECK
        ));
    }

    public function down(): void
    {
        if (! Schema::hasTable('song_authors')) {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $constraints = [
            self::DISPLAY_NAME_FORMAT_CHECK,
            self::FIRST_NAME_FORMAT_CHECK,
            self::LAST_NAME_FORMAT_CHECK,
        ];

        foreach ($constraints as $constraint) {
            $constraintExists = DB::select("
                SELECT CONSTRAINT_NAME
                FROM information_schema.TABLE_CONSTRAINTS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'song_authors'
                AND CONSTRAINT_NAME = ?
            ", [$constraint]);

            if (! empty($constraintExists)) {
                DB::statement(sprintf('ALTER TABLE song_authors DROP CHECK %s', $constraint));
            }
        }
    }
};
