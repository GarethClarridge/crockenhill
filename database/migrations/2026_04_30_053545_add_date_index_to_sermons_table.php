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
        Schema::table('sermons', function (Blueprint $table) {
            if (! $this->indexExists('sermons', 'sermons_date_index')) {
                $table->index('date', 'sermons_date_index');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sermons', function (Blueprint $table) {
            if ($this->indexExists('sermons', 'sermons_date_index')) {
                $table->dropIndex('sermons_date_index');
            }
        });
    }

    /**
     * Check if an index exists on a table
     */
    private function indexExists(string $table, string $indexName): bool
    {
        return Schema::hasIndex($table, $indexName);
    }
};
