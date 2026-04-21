<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sermons', function (Blueprint $table) {
            $table->string('audio_file_path', 255)->nullable()->change();
        });

        // Convert existing empty-string "no audio" values to NULL.
        DB::table('sermons')->where('audio_file_path', '')->update(['audio_file_path' => null]);
    }

    public function down(): void
    {
        // Restore empty strings before making non-nullable again.
        DB::table('sermons')->whereNull('audio_file_path')->update(['audio_file_path' => '']);

        Schema::table('sermons', function (Blueprint $table) {
            $table->string('audio_file_path', 255)->nullable(false)->change();
        });
    }
};
