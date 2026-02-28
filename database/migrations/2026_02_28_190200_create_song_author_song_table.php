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
        Schema::create('song_author_song', function (Blueprint $table): void {
            $table->foreignId('song_id')->constrained()->cascadeOnDelete();
            $table->foreignId('song_author_id')->constrained()->cascadeOnDelete();
            $table->string('author_type')->default('');

            $table->unique(['song_id', 'song_author_id', 'author_type'], 'song_author_song_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('song_author_song');
    }
};
