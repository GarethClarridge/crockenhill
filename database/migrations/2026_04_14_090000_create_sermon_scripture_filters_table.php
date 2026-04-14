<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sermon_scripture_filters', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('sermon_id');
            $table->string('bible_book', 50);
            $table->unsignedSmallInteger('bible_chapter');
            $table->timestamps();

            $table->unique(['sermon_id', 'bible_book', 'bible_chapter'], 'sermon_scripture_filters_unique');
            $table->index(['bible_book', 'bible_chapter', 'sermon_id'], 'sermon_scripture_filters_book_chapter_sermon_index');
            $table->index(['bible_book', 'sermon_id'], 'sermon_scripture_filters_book_sermon_index');
            $table->foreign('sermon_id')->references('id')->on('sermons')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sermon_scripture_filters');
    }
};
