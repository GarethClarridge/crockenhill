<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sermons', function (Blueprint $table) {
            $table->string('audio_file_path', 255)->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Truncate long paths to avoid rollback failure in MySQL strict mode
        DB::table('sermons')
            ->whereRaw('LENGTH(audio_file_path) > 75')
            ->update(['audio_file_path' => DB::raw('LEFT(audio_file_path, 75)')]);

        Schema::table('sermons', function (Blueprint $table) {
            $table->string('audio_file_path', 75)->nullable(false)->change();
        });
    }
};
