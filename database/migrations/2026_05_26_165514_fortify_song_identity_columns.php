<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TITLE_CHECK = 'songs_title_check';

    private const CANONICAL_KEY_CHECK = 'songs_canonical_key_check';

    private const ALTERNATE_TITLE_CHECK = 'songs_alternate_title_check';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasTable('songs')) {
            return;
        }

        // 1. Data Cleanup: Normalize existing data where possible (trimming and lowercasing)
        // For canonical_key we also normalize whitespace as per the model's canonicalizeKey().
        // Repair any records that would end up empty or duplicated if needed, though
        // Warden principles prefer failing if corruption is severe.
        DB::table('songs')->update([
            'title' => DB::raw('TRIM(title)'),
            'canonical_key' => DB::raw("LOWER(TRIM(REGEXP_REPLACE(canonical_key, '[[:space:]]+', ' ')))"),
            'alternate_title' => DB::raw("NULLIF(TRIM(alternate_title), '')"),
        ]);

        // If trimming title results in empty strings, use a fallback to satisfy the constraint
        DB::table('songs')
            ->where('title', '')
            ->update(['title' => DB::raw("CONCAT('Song ', id)")]);

        // If normalizing canonical_key results in empty strings, use a fallback
        DB::table('songs')
            ->where('canonical_key', '')
            ->update(['canonical_key' => DB::raw("CONCAT('legacy-song-', id)")]);

        // 2. Drop existing basic NOT EMPTY constraints to replace them with strict BINARY trim checks.
        $this->dropConstraintIfExists('songs', self::TITLE_CHECK);
        $this->dropConstraintIfExists('songs', self::CANONICAL_KEY_CHECK);

        // 3. Add upgraded/new CHECK constraints.
        // BINARY ensures exact character-for-character match for the trim check.
        DB::statement(sprintf(
            "ALTER TABLE songs ADD CONSTRAINT %s CHECK (BINARY title = TRIM(title) AND title != '')",
            self::TITLE_CHECK
        ));

        DB::statement(sprintf(
            "ALTER TABLE songs ADD CONSTRAINT %s CHECK (BINARY canonical_key = LOWER(TRIM(REGEXP_REPLACE(canonical_key, '[[:space:]]+', ' '))) AND canonical_key != '')",
            self::CANONICAL_KEY_CHECK
        ));

        DB::statement(sprintf(
            "ALTER TABLE songs ADD CONSTRAINT %s CHECK (alternate_title IS NULL OR (BINARY alternate_title = TRIM(alternate_title) AND alternate_title != ''))",
            self::ALTERNATE_TITLE_CHECK
        ));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasTable('songs')) {
            return;
        }

        $this->dropConstraintIfExists('songs', self::TITLE_CHECK);
        $this->dropConstraintIfExists('songs', self::CANONICAL_KEY_CHECK);
        $this->dropConstraintIfExists('songs', self::ALTERNATE_TITLE_CHECK);

        // Restore legacy basic NOT EMPTY constraints if they existed.
        // We know they existed from previous migrations.
        DB::statement(sprintf("ALTER TABLE songs ADD CONSTRAINT %s CHECK (title <> '')", self::TITLE_CHECK));
        DB::statement(sprintf("ALTER TABLE songs ADD CONSTRAINT %s CHECK (canonical_key <> '')", self::CANONICAL_KEY_CHECK));
    }

    private function dropConstraintIfExists(string $table, string $constraint): void
    {
        $constraintExists = DB::table('information_schema.table_constraints')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('constraint_name', $constraint)
            ->exists();

        if ($constraintExists) {
            DB::statement(sprintf('ALTER TABLE %s DROP CHECK %s', $table, $constraint));
        }
    }
};
