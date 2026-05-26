<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CONSTRAINT_NAME = 'song_videos_video_file_path_format_check';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasTable('song_videos')) {
            return;
        }

        if ($this->constraintExists('song_videos', self::CONSTRAINT_NAME)) {
            return;
        }

        // Data Cleanup: normalise existing data before applying the constraint.
        DB::table('song_videos')
            ->whereNotNull('video_file_path')
            ->update(['video_file_path' => DB::raw('TRIM(video_file_path)')]);

        DB::statement('ALTER TABLE song_videos ADD CONSTRAINT '.self::CONSTRAINT_NAME." CHECK (video_file_path != '' AND BINARY video_file_path = TRIM(video_file_path))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasTable('song_videos')) {
            return;
        }

        if (! $this->constraintExists('song_videos', self::CONSTRAINT_NAME)) {
            return;
        }

        DB::statement('ALTER TABLE song_videos DROP CHECK '.self::CONSTRAINT_NAME);
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
