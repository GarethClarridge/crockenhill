<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('song_book_song')) {
            return;
        }

        Schema::create('song_book_song', function (Blueprint $table): void {
            $table->foreignId('song_id')->constrained()->cascadeOnDelete();
            $table->foreignId('song_book_id')->constrained()->cascadeOnDelete();
            $table->string('entry');

            $table->unique(['song_id', 'song_book_id', 'entry'], 'song_book_song_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('song_book_song');
    }
};
