<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CONSTRAINT_NAME = 'sermons_audio_file_path_format_check';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('sermons')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE sermons ADD CONSTRAINT '.self::CONSTRAINT_NAME." CHECK (audio_file_path != '' AND BINARY audio_file_path = TRIM(audio_file_path))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql' && Schema::hasTable('sermons')) {
            DB::statement('ALTER TABLE sermons DROP CHECK '.self::CONSTRAINT_NAME);
        }
    }
};
