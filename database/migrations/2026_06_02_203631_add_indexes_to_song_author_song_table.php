<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('song_author_song', function (Blueprint $table) {
            $table->index('song_id');
            $table->index('song_author_id');
        });
    }

    public function down(): void
    {
        Schema::table('song_author_song', function (Blueprint $table) {
            $table->dropIndex(['song_id']);
            $table->dropIndex(['song_author_id']);
        });
    }
};
