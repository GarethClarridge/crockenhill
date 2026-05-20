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
        if (! Schema::hasTable('song_videos')) {
            return;
        }

        /**
         * Warden Constraint Principle: Integrity migrations must not modify existing data.
         * If existing data violates a proposed constraint, the migration must fail
         * to surface the issue for manual intervention or a separate backfill.
         */
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE song_videos ADD CONSTRAINT '.self::CONSTRAINT_NAME." CHECK (video_file_path != '' AND BINARY video_file_path = TRIM(video_file_path))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql' && Schema::hasTable('song_videos')) {
            DB::statement('ALTER TABLE song_videos DROP CHECK '.self::CONSTRAINT_NAME);
        }
    }
};
