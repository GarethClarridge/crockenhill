<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const NAME_FORMAT_CHECK = 'song_books_name_format_check';

    private const PUBLISHER_FORMAT_CHECK = 'song_books_publisher_format_check';

    private const LEGACY_NAME_CHECK = 'song_books_name_check';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('song_books')) {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // 1. Drop legacy constraint if it exists
        $this->dropConstraintIfExists(self::LEGACY_NAME_CHECK);

        // 2. Data Cleanup: Trim existing data
        DB::table('song_books')->update([
            'name' => DB::raw('TRIM(name)'),
            'publisher' => DB::raw('TRIM(publisher)'),
        ]);

        // 3. Ensure no empty names exist before adding constraint
        DB::table('song_books')
            ->where('name', '')
            ->update(['name' => DB::raw("CONCAT('Songbook ', id)")]);

        // 4. Add CHECK constraints
        // BINARY ensures exact character-for-character match for the trim check.
        DB::statement(sprintf(
            "ALTER TABLE song_books ADD CONSTRAINT %s CHECK (BINARY name = TRIM(name) AND name != '')",
            self::NAME_FORMAT_CHECK
        ));

        DB::statement(sprintf(
            "ALTER TABLE song_books ADD CONSTRAINT %s CHECK (publisher IS NULL OR (BINARY publisher = TRIM(publisher) AND publisher != ''))",
            self::PUBLISHER_FORMAT_CHECK
        ));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('song_books')) {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $this->dropConstraintIfExists(self::NAME_FORMAT_CHECK);
        $this->dropConstraintIfExists(self::PUBLISHER_FORMAT_CHECK);

        // Restore legacy constraint
        DB::statement(sprintf("ALTER TABLE song_books ADD CONSTRAINT %s CHECK (name <> '')", self::LEGACY_NAME_CHECK));
    }

    private function dropConstraintIfExists(string $constraintName): void
    {
        $constraintExists = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'song_books'
            AND CONSTRAINT_NAME = ?
        ", [$constraintName]);

        if (! empty($constraintExists)) {
            DB::statement(sprintf('ALTER TABLE song_books DROP CHECK %s', $constraintName));
        }
    }
};
