<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const AUDIO_FILE_PATH_NOT_EMPTY_CHECK = 'sermons_audio_file_path_not_empty_check';

    private const TITLE_NOT_EMPTY_CHECK = 'sermons_title_not_empty_check';

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

        // Add CHECK constraints to ensure mandatory fields are not empty.
        // These columns are already NOT NULL, but this prevents empty strings.

        if (! $this->constraintExists('sermons', self::AUDIO_FILE_PATH_NOT_EMPTY_CHECK)) {
            DB::statement(sprintf(
                "ALTER TABLE sermons ADD CONSTRAINT %s CHECK (audio_file_path != '')",
                self::AUDIO_FILE_PATH_NOT_EMPTY_CHECK
            ));
        }

        if (! $this->constraintExists('sermons', self::TITLE_NOT_EMPTY_CHECK)) {
            DB::statement(sprintf(
                "ALTER TABLE sermons ADD CONSTRAINT %s CHECK (title != '')",
                self::TITLE_NOT_EMPTY_CHECK
            ));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if ($this->constraintExists('sermons', self::AUDIO_FILE_PATH_NOT_EMPTY_CHECK)) {
            DB::statement(sprintf('ALTER TABLE sermons DROP CHECK %s', self::AUDIO_FILE_PATH_NOT_EMPTY_CHECK));
        }

        if ($this->constraintExists('sermons', self::TITLE_NOT_EMPTY_CHECK)) {
            DB::statement(sprintf('ALTER TABLE sermons DROP CHECK %s', self::TITLE_NOT_EMPTY_CHECK));
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
