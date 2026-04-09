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

            // Unique constraint on (bible_id, normalized_reference) already exists from
            // 2026_03_13_194852_create_scripture_passages_table.php.
            // We add an index on fetched_at for performance in refresh queries.
            $table->index('fetched_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scripture_passages', function (Blueprint $table) {
            $table->dropIndex(['fetched_at']);

            $table->string('bible_id')->change();
            $table->string('normalized_reference')->change();
            $table->string('api_passage_id')->nullable()->change();
            $table->string('display_reference')->nullable()->change();
            $table->string('fums_token')->nullable()->change();
        });
    }
};
