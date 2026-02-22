<?php

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
        // Fix duplicate slugs in sermons table before adding unique constraint
        $this->fixDuplicateSlugs('sermons');

        // Sermons table indexes
        Schema::table('sermons', function (Blueprint $table) {
            if (! $this->indexExists('sermons', 'sermons_date_service_index')) {
                $table->index(['date', 'service'], 'sermons_date_service_index');
            }
            if (! $this->indexExists('sermons', 'sermons_preacher_index')) {
                $table->index('preacher', 'sermons_preacher_index');
            }
            if (! $this->indexExists('sermons', 'sermons_series_index')) {
                $table->index('series', 'sermons_series_index');
            }
            if (! $this->indexExists('sermons', 'sermons_slug_unique')) {
                $table->unique('slug', 'sermons_slug_unique');
            }
        });

        // Fix duplicate slugs in pages table before adding unique constraint
        $this->fixDuplicateSlugs('pages');

        // Pages table indexes
        Schema::table('pages', function (Blueprint $table) {
            if (! $this->indexExists('pages', 'pages_slug_unique')) {
                $table->unique('slug', 'pages_slug_unique');
            }
            if (! $this->indexExists('pages', 'pages_area_index')) {
                $table->index('area', 'pages_area_index');
            }
        });

        // Fix duplicate slugs in meetings table before adding unique constraint
        $this->fixDuplicateSlugs('meetings');

        // Meetings table indexes
        Schema::table('meetings', function (Blueprint $table) {
            if (! $this->indexExists('meetings', 'meetings_slug_unique')) {
                $table->unique('slug', 'meetings_slug_unique');
            }
            if (! $this->indexExists('meetings', 'meetings_type_day_index')) {
                $table->index(['type', 'day'], 'meetings_type_day_index');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Sermons table indexes
        Schema::table('sermons', function (Blueprint $table) {
            $table->dropIndex('sermons_date_service_index');
            $table->dropIndex('sermons_preacher_index');
            $table->dropIndex('sermons_series_index');
            $table->dropUnique('sermons_slug_unique');
        });

        // Pages table indexes
        Schema::table('pages', function (Blueprint $table) {
            $table->dropUnique('pages_slug_unique');
            $table->dropIndex('pages_area_index');
        });

        // Meetings table indexes
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropUnique('meetings_slug_unique');
            $table->dropIndex('meetings_type_day_index');
        });
    }

    /**
     * Check if an index exists on a table
     */
    private function indexExists(string $table, string $indexName): bool
    {
        if (DB::getDriverName() === 'mysql') {
            $indexes = DB::select("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$indexName]);

            return ! empty($indexes);
        }

        // Fallback for other drivers (like SQLite during tests)
        if (method_exists(Schema::class, 'getIndexes')) {
            $indexes = Schema::getIndexes($table);
            foreach ($indexes as $index) {
                if ($index['name'] === $indexName) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }

    /**
     * Fix duplicate slugs by appending a number to duplicates
     */
    private function fixDuplicateSlugs(string $table): void
    {
        // Find duplicate slugs
        $duplicates = DB::table($table)
            ->select('slug')
            ->groupBy('slug')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('slug');

        foreach ($duplicates as $slug) {
            // Get all records with this slug, ordered by id
            $records = DB::table($table)
                ->where('slug', $slug)
                ->orderBy('id')
                ->get();

            // Keep the first record as is, update others
            foreach ($records->skip(1) as $index => $record) {
                $newSlug = $slug.'-'.($index + 2);

                // Ensure the new slug doesn't already exist
                $counter = 2;
                while (DB::table($table)->where('slug', $newSlug)->exists()) {
                    $counter++;
                    $newSlug = $slug.'-'.$counter;
                }

                DB::table($table)
                    ->where('id', $record->id)
                    ->update(['slug' => $newSlug]);
            }
        }
    }
};
