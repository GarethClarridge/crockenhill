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
        // 1. Ensure no duplicates exist before adding the constraint
        $this->resolveDuplicates();

        // 2. Update schema
        Schema::table('meetings', function (Blueprint $table) {
            // In MySQL, we need to drop the foreign key before we can change its supporting index
            $table->dropForeign('meetings_page_id_foreign');

            // Drop the old non-unique index if it was separately named (standard Laravel behavior)
            if (Schema::hasIndex('meetings', 'meetings_page_id_foreign')) {
                $table->dropIndex('meetings_page_id_foreign');
            }

            // Add the unique index
            $table->unique('page_id', 'meetings_page_id_unique');

            // Re-add the foreign key, now it will be backed by the unique index
            $table->foreign('page_id', 'meetings_page_id_foreign')
                ->references('id')
                ->on('pages')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            // Drop foreign key again to allow index modification
            $table->dropForeign('meetings_page_id_foreign');

            // Drop unique index
            $table->dropUnique('meetings_page_id_unique');

            // Restore the original non-unique index
            $table->index('page_id', 'meetings_page_id_foreign');

            // Restore foreign key
            $table->foreign('page_id', 'meetings_page_id_foreign')
                ->references('id')
                ->on('pages')
                ->onDelete('set null');
        });
    }

    /**
     * Find and resolve duplicate page_id values in the meetings table.
     * Keeps the oldest meeting for each page and nullifies the others.
     */
    private function resolveDuplicates(): void
    {
        $duplicates = DB::table('meetings')
            ->select('page_id')
            ->whereNotNull('page_id')
            ->groupBy('page_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('page_id');

        foreach ($duplicates as $pageId) {
            $meetings = DB::table('meetings')
                ->where('page_id', $pageId)
                ->orderBy('id')
                ->pluck('id');

            if ($meetings->count() > 1) {
                DB::table('meetings')
                    ->whereIn('id', $meetings->skip(1))
                    ->update(['page_id' => null]);
            }
        }
    }
};
