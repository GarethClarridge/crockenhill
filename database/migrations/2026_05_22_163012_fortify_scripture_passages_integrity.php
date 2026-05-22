<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const BIBLE_ID_FORMAT_CHECK = 'scripture_passages_bible_id_format_check';

    private const NORMALIZED_REF_FORMAT_CHECK = 'scripture_passages_normalized_reference_format_check';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('scripture_passages')) {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // 1. Data Cleanup: Trim existing data
        DB::table('scripture_passages')->update([
            'bible_id' => DB::raw('TRIM(bible_id)'),
            'normalized_reference' => DB::raw('TRIM(normalized_reference)'),
        ]);

        // 2. Add CHECK constraints
        // BINARY ensures exact character-for-character match for the trim check.
        if (! $this->constraintExists('scripture_passages', self::BIBLE_ID_FORMAT_CHECK)) {
            DB::statement(sprintf(
                "ALTER TABLE scripture_passages ADD CONSTRAINT %s CHECK (BINARY bible_id = TRIM(bible_id) AND bible_id != '')",
                self::BIBLE_ID_FORMAT_CHECK
            ));
        }

        if (! $this->constraintExists('scripture_passages', self::NORMALIZED_REF_FORMAT_CHECK)) {
            DB::statement(sprintf(
                "ALTER TABLE scripture_passages ADD CONSTRAINT %s CHECK (BINARY normalized_reference = TRIM(normalized_reference) AND normalized_reference != '')",
                self::NORMALIZED_REF_FORMAT_CHECK
            ));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('scripture_passages')) {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $this->dropConstraintIfExists('scripture_passages', self::BIBLE_ID_FORMAT_CHECK);
        $this->dropConstraintIfExists('scripture_passages', self::NORMALIZED_REF_FORMAT_CHECK);
    }

    private function dropConstraintIfExists(string $table, string $constraintName): void
    {
        if ($this->constraintExists($table, $constraintName)) {
            DB::statement(sprintf('ALTER TABLE %s DROP CHECK %s', $table, $constraintName));
        }
    }

    private function constraintExists(string $table, string $constraint): bool
    {
        return DB::table('information_schema.table_constraints')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('constraint_name', $constraint)
            ->exists();
    }
};
