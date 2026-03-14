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
        // 1. Resolve duplicates for (area, slug) before adding the constraint
        $this->resolveDuplicates();

        Schema::table('pages', function (Blueprint $table) {
            // 2. Drop the existing unique constraint on slug if it exists
            if (Schema::hasIndex('pages', 'pages_slug_unique')) {
                $table->dropUnique('pages_slug_unique');
            }

            // 3. Add composite unique index on (area, slug)
            $table->unique(['area', 'slug'], 'pages_area_slug_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            // Drop composite unique
            $table->dropUnique('pages_area_slug_unique');

            // Restore individual slug unique index
            $table->unique('slug', 'pages_slug_unique');
        });
    }

    private function resolveDuplicates(): void
    {
        $duplicates = DB::table('pages')
            ->select('area', 'slug')
            ->groupBy('area', 'slug')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $records = DB::table('pages')
                ->where('area', $duplicate->area)
                ->where('slug', $duplicate->slug)
                ->orderBy('id')
                ->get();

            // Keep first, rename others
            foreach ($records->skip(1) as $index => $record) {
                $newSlug = $duplicate->slug.'-'.($index + 2);

                // Ensure newSlug is also unique for this area
                $counter = 2;
                while (DB::table('pages')->where('area', $duplicate->area)->where('slug', $newSlug)->exists()) {
                    $counter++;
                    $newSlug = $duplicate->slug.'-'.$counter;
                }

                DB::table('pages')
                    ->where('id', $record->id)
                    ->update(['slug' => $newSlug]);
            }
        }
    }
};
