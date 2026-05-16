<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_NAME_CHECK = 'song_books_name_check';

    private const NAME_FORMAT_CHECK = 'song_books_name_format_check';

    private const PUBLISHER_FORMAT_CHECK = 'song_books_publisher_format_check';

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

        // 1. Data Cleanup: Normalize existing data
        DB::table('song_books')->update([
            'name' => DB::raw('TRIM(name)'),
            'publisher' => DB::raw('NULLIF(TRIM(publisher), "")'),
        ]);

        // 2. Drop legacy weak constraint
        $this->dropConstraintIfExists('song_books', self::OLD_NAME_CHECK);

        // 3. Add robust CHECK constraints
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

        $this->dropConstraintIfExists('song_books', self::NAME_FORMAT_CHECK);
        $this->dropConstraintIfExists('song_books', self::PUBLISHER_FORMAT_CHECK);

        // Restore legacy weak constraint
        DB::statement(sprintf("ALTER TABLE song_books ADD CONSTRAINT %s CHECK (name <> '')", self::OLD_NAME_CHECK));
    }

    private function dropConstraintIfExists(string $table, string $constraint): void
    {
        $exists = DB::table('information_schema.table_constraints')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('constraint_name', $constraint)
            ->exists();

        if ($exists) {
            DB::statement(sprintf('ALTER TABLE %s DROP CHECK %s', $table, $constraint));
        }
    }
};
