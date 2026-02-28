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
        if (Schema::hasTable('song_books')) {
            return;
        }

        Schema::create('song_books', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('source_book_id')->unique();
            $table->string('name');
            $table->string('publisher')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('song_books');
    }
};
