<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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

        $this->cleanOrphanedServiceSectionItems();

        Schema::table('service_sections', function (Blueprint $table) {
            $table->foreign('matched_item_id')
                ->references('id')
                ->on('church_service_items')
                ->nullOnDelete();

            $table->foreign('expected_item_id')
                ->references('id')
                ->on('church_service_items')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_sections', function (Blueprint $table) {
            $table->dropForeign(['matched_item_id']);
            $table->dropForeign(['expected_item_id']);
        });

        Schema::table('sermons', function (Blueprint $table) {
            if ($this->indexExists('sermons', 'sermons_date_index')) {
                $table->dropIndex('sermons_date_index');
            }
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return collect(Schema::getIndexes($table))->contains('name', $indexName);
    }

    private function cleanOrphanedServiceSectionItems(): void
    {
        // Null out matched_item_id if it doesn't exist in church_service_items
        DB::table('service_sections')
            ->whereNotNull('matched_item_id')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('church_service_items')
                    ->whereColumn('church_service_items.id', 'service_sections.matched_item_id');
            })
            ->update(['matched_item_id' => null]);

        // Null out expected_item_id if it doesn't exist in church_service_items
        DB::table('service_sections')
            ->whereNotNull('expected_item_id')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('church_service_items')
                    ->whereColumn('church_service_items.id', 'service_sections.expected_item_id');
            })
            ->update(['expected_item_id' => null]);
    }
};
