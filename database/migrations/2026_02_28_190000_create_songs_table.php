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
        Schema::create('songs', function (Blueprint $table): void {
            $table->id();
            $table->string('canonical_key')->unique();
            $table->string('title');
            $table->string('alternate_title')->nullable();
            $table->longText('lyrics_xml');
            $table->longText('lyrics_plain')->nullable();
            $table->string('verse_order')->nullable();
            $table->string('copyright')->nullable();
            $table->longText('comments')->nullable();
            $table->string('ccli_number')->nullable();
            $table->json('import_metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('ccli_number');
            $table->index('deleted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('songs');
    }
};
