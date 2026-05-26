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

        // 1. Normalise title and alternate_title (trimming only — no collision risk on these).
        DB::table('songs')->update([
            'title' => DB::raw('TRIM(title)'),
            'alternate_title' => DB::raw("NULLIF(TRIM(alternate_title), '')"),
        ]);

        // Fallback for titles that trimmed to empty.
        DB::table('songs')
            ->where('title', '')
            ->update(['title' => DB::raw("CONCAT('Song ', id)")]);

        // 2. Normalise canonical_key to match Song::canonicalizeKey():
        //    a) Strip @ suffix (SUBSTRING_INDEX gives the part before the first @).
        //    b) Lowercase, trim, and collapse internal whitespace.
        DB::table('songs')->update([
            'canonical_key' => DB::raw(
                "LOWER(TRIM(REGEXP_REPLACE(TRIM(SUBSTRING_INDEX(canonical_key, '@', 1)), '[[:space:]]+', ' ')))"
            ),
        ]);

        // Fallback for keys that normalised to empty.
        DB::table('songs')
            ->where('canonical_key', '')
            ->update(['canonical_key' => DB::raw("CONCAT('legacy-song-', id)")]);

        // 3. Resolve any canonical_key collisions introduced by normalisation.
        //    For each set of duplicate keys, keep the lowest-id row unchanged and
        //    append -<id> to every other row so the UNIQUE index is not violated.
        $duplicates = DB::table('songs')
            ->select('canonical_key')
            ->groupBy('canonical_key')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('canonical_key');

        foreach ($duplicates as $key) {
            // Fetch all rows sharing this key, ordered by id so the first is kept.
            $ids = DB::table('songs')
                ->where('canonical_key', $key)
                ->orderBy('id')
                ->pluck('id');

            // Skip the first (keeper); rename the rest.
            foreach ($ids->skip(1) as $id) {
                DB::table('songs')
                    ->where('id', $id)
                    ->update(['canonical_key' => $key.'-'.$id]);
            }
        }

        // 4. Drop existing basic NOT EMPTY constraints to replace them with strict BINARY trim checks.
        $this->dropConstraintIfExists('songs', self::TITLE_CHECK);
        $this->dropConstraintIfExists('songs', self::CANONICAL_KEY_CHECK);

        // 5. Add upgraded/new CHECK constraints.
        //    BINARY ensures exact character-for-character match for the trim check.
        DB::statement(sprintf(
            "ALTER TABLE songs ADD CONSTRAINT %s CHECK (BINARY title = TRIM(title) AND title != '')",
            self::TITLE_CHECK
        ));

        DB::statement(sprintf(
            "ALTER TABLE songs ADD CONSTRAINT %s CHECK (BINARY canonical_key = LOWER(TRIM(REGEXP_REPLACE(canonical_key, '[[:space:]]+', ' '))) AND canonical_key != '' AND LOCATE('@', canonical_key) = 0)",
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
