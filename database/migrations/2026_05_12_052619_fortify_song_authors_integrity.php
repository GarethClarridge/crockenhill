<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DISPLAY_NAME_CHECK = 'song_authors_display_name_format_check';

    private const FIRST_NAME_CHECK = 'song_authors_first_name_format_check';

    private const LAST_NAME_CHECK = 'song_authors_last_name_format_check';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // 1. Data Cleanup: Normalize existing data before adding constraints
        DB::table('song_authors')->update([
            'display_name' => DB::raw('TRIM(display_name)'),
            'first_name' => DB::raw("NULLIF(TRIM(first_name), '')"),
            'last_name' => DB::raw("NULLIF(TRIM(last_name), '')"),
        ]);

        // Repair any authors that ended up with an empty display_name after trimming
        DB::table('song_authors')
            ->where('display_name', '')
            ->update(['display_name' => DB::raw("CONCAT('Author ', id)")]);

        // 2. Ensure display_name is non-nullable
        Schema::table('song_authors', function (Blueprint $table) {
            $table->string('display_name')->nullable(false)->change();
        });

        // 3. Drop legacy constraint if it exists (from 2026_04_04_150531_add_integrity_checks_to_song_catalog_tables)
        $this->dropConstraintIfExists('song_authors', 'song_authors_display_name_check');

        // 4. Add fortified CHECK constraints
        // BINARY ensures exact character-for-character match for the trim check.
        DB::statement(sprintf(
            "ALTER TABLE song_authors ADD CONSTRAINT %s CHECK (BINARY display_name = TRIM(display_name) AND display_name != '')",
            self::DISPLAY_NAME_CHECK
        ));

        DB::statement(sprintf(
            "ALTER TABLE song_authors ADD CONSTRAINT %s CHECK (first_name IS NULL OR (BINARY first_name = TRIM(first_name) AND first_name != ''))",
            self::FIRST_NAME_CHECK
        ));

        DB::statement(sprintf(
            "ALTER TABLE song_authors ADD CONSTRAINT %s CHECK (last_name IS NULL OR (BINARY last_name = TRIM(last_name) AND last_name != ''))",
            self::LAST_NAME_CHECK
        ));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(sprintf('ALTER TABLE song_authors DROP CHECK %s', self::DISPLAY_NAME_CHECK));
        DB::statement(sprintf('ALTER TABLE song_authors DROP CHECK %s', self::FIRST_NAME_CHECK));
        DB::statement(sprintf('ALTER TABLE song_authors DROP CHECK %s', self::LAST_NAME_CHECK));

        // Restore legacy constraint
        DB::statement("ALTER TABLE song_authors ADD CONSTRAINT song_authors_display_name_check CHECK (display_name <> '')");
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
