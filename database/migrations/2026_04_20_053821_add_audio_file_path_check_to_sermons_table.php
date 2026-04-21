<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
            Schema::table('sermons', function (Blueprint $table) {
                // Constraint: Not empty and trimmed (TRIM matches original)
                // Use native Laravel 12 check() method
                $table->check(
                    "audio_file_path != '' AND BINARY audio_file_path = TRIM(audio_file_path)",
                    self::CONSTRAINT_NAME
                );
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql' && Schema::hasTable('sermons')) {
            // MySQL 8.0.16+ doesn't support DROP CHECK IF EXISTS
            // We can wrap it in a try-catch or check existence manually if needed,
            // but for a single targeted migration, standard dropCheck is usually fine.
            Schema::table('sermons', function (Blueprint $table) {
                $table->dropCheck(self::CONSTRAINT_NAME);
            });
        }
    }
};
