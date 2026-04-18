<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Add the CHECK constraint
        // Ensures audio_file_path is not empty and has no leading/trailing whitespace.
        // NOTE: We are NOT performing data cleanup here to comply with safety guidelines.
        DB::statement("ALTER TABLE sermons ADD CONSTRAINT sermons_audio_file_path_format_check CHECK (audio_file_path = TRIM(audio_file_path) AND audio_file_path != '')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE sermons DROP CHECK sermons_audio_file_path_format_check');
    }
};
