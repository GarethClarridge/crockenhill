<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const VIDEO_FILE_PATH_CHECK = 'song_videos_video_file_path_check';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql' && Schema::hasTable('song_videos')) {
            if (! $this->constraintExists('song_videos', self::VIDEO_FILE_PATH_CHECK)) {
                DB::statement(sprintf(
                    "ALTER TABLE song_videos ADD CONSTRAINT %s CHECK (video_file_path <> '')",
                    self::VIDEO_FILE_PATH_CHECK
                ));
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql' && Schema::hasTable('song_videos')) {
            if ($this->constraintExists('song_videos', self::VIDEO_FILE_PATH_CHECK)) {
                DB::statement(sprintf('ALTER TABLE song_videos DROP CHECK %s', self::VIDEO_FILE_PATH_CHECK));
            }
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
