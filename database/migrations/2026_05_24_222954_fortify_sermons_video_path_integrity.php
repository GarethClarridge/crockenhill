<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CONSTRAINT_NAME = 'sermons_video_file_path_format_check';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('sermons')) {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // 1. Data Cleanup: Trim existing data
        DB::table('sermons')->update([
            'video_file_path' => DB::raw('TRIM(video_file_path)'),
        ]);

        // 2. Add CHECK constraint
        // BINARY ensures exact character-for-character match for the trim check.
        if (! $this->constraintExists('sermons', self::CONSTRAINT_NAME)) {
            DB::statement(sprintf(
                "ALTER TABLE sermons ADD CONSTRAINT %s CHECK (video_file_path IS NULL OR (BINARY video_file_path = TRIM(video_file_path) AND video_file_path != ''))",
                self::CONSTRAINT_NAME
            ));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('sermons')) {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $this->dropConstraintIfExists('sermons', self::CONSTRAINT_NAME);
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
