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

        // Data Cleanup: Normalize invalid data before adding constraints
        // 1. Trim existing paths
        DB::table('sermons')
            ->whereRaw('TRIM(audio_file_path) != audio_file_path')
            ->update(['audio_file_path' => DB::raw('TRIM(audio_file_path)')]);

        // 2. Set a placeholder for empty paths (if any) to satisfy the constraint
        // Note: The column is NOT NULL, but could be an empty string.
        DB::table('sermons')
            ->where('audio_file_path', '')
            ->update(['audio_file_path' => 'pending-audio-path']);

        if (DB::getDriverName() === 'mysql') {
            // Constraint: Not empty and trimmed (TRIM matches original)
            DB::statement(sprintf(
                "ALTER TABLE sermons ADD CONSTRAINT %s CHECK (audio_file_path != '' AND BINARY audio_file_path = TRIM(audio_file_path))",
                self::CONSTRAINT_NAME
            ));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement(sprintf('ALTER TABLE sermons DROP CHECK %s', self::CONSTRAINT_NAME));
        }
    }
};
