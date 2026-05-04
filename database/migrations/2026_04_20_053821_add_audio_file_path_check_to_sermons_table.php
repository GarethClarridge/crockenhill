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

        Schema::table('sermons', function (Blueprint $table): void {
            $table->string('audio_file_path', 255)->nullable()->change();
        });

        DB::table('sermons')
            ->whereNotNull('audio_file_path')
            ->update(['audio_file_path' => DB::raw('TRIM(audio_file_path)')]);

        DB::table('sermons')
            ->where('audio_file_path', '')
            ->update(['audio_file_path' => null]);

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE sermons ADD CONSTRAINT '.self::CONSTRAINT_NAME." CHECK (audio_file_path IS NULL OR (audio_file_path != '' AND BINARY audio_file_path = TRIM(audio_file_path)))");
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
