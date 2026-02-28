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
        Schema::table('church_service_items', function (Blueprint $table): void {
            $table->foreignId('song_id')
                ->nullable()
                ->after('openlp_search_title')
                ->constrained('songs')
                ->nullOnDelete();

            $table->index('song_id');
            $table->index(['type', 'song_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('church_service_items', function (Blueprint $table): void {
            $table->dropIndex(['type', 'song_id']);
            $table->dropIndex(['song_id']);
            $table->dropConstrainedForeignId('song_id');
        });
    }
};
