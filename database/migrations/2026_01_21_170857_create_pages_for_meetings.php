<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates a Page record for each Meeting and links them together.
     */
    public function up(): void
    {
        // Get all meetings
        $meetings = DB::table('meetings')->get();

        foreach ($meetings as $meeting) {
            // Check if a page with this slug already exists in community area
            $existingPage = DB::table('pages')
                ->where('slug', $meeting->slug)
                ->where('area', 'community')
                ->first();

            if ($existingPage) {
                // Link meeting to existing page
                $pageId = $existingPage->id;
            } else {
                // Create a page for this meeting
                $pageId = DB::table('pages')->insertGetId([
                    'slug' => $meeting->slug,
                    'heading' => Str::title(str_replace('-', ' ', $meeting->slug)),
                    'area' => 'community',
                    'navigation' => false,
                    'description' => "Details for {$meeting->who}",
                    'body' => '',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Link meeting to page
            DB::table('meetings')
                ->where('id', $meeting->id)
                ->update(['page_id' => $pageId]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove page_id references
        DB::table('meetings')->update(['page_id' => null]);

        // Note: We don't delete the auto-created pages as they may have been edited
    }
};
