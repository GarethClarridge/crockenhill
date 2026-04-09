<?php

declare(strict_types=1);

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
        Schema::table('scripture_passages', function (Blueprint $table) {
            $table->string('bible_id', 255)->change();
            $table->string('normalized_reference', 255)->change();
            $table->string('api_passage_id', 255)->nullable()->change();
            $table->string('display_reference', 255)->nullable()->change();
            $table->string('fums_token', 255)->nullable()->change();

            // The unique constraint already exists from 2026_03_13_194852_create_scripture_passages_table.php
            // but we ensure it's backed by indexes for the individual columns as well for performance.
            $table->index('bible_id');
            $table->index('normalized_reference');
            $table->index('fetched_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scripture_passages', function (Blueprint $table) {
            $table->dropIndex(['bible_id']);
            $table->dropIndex(['normalized_reference']);
            $table->dropIndex(['fetched_at']);
        });
    }
};
