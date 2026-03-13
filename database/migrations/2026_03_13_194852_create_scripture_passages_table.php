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
        Schema::create('scripture_passages', function (Blueprint $table) {
            $table->id();
            $table->string('bible_id');
            $table->string('normalized_reference');
            $table->string('api_passage_id')->nullable();
            $table->string('display_reference')->nullable();
            $table->longText('html_content');
            $table->text('copyright');
            $table->string('fums_token')->nullable();
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['bible_id', 'normalized_reference']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scripture_passages');
    }
};
