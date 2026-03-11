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
        // Ensure no orphaned records exist before adding the constraint
        DB::table('calendar_events')
            ->whereNotNull('meeting_slug')
            ->whereNotIn('meeting_slug', function ($query) {
                $query->select('slug')->from('meetings');
            })
            ->update(['meeting_slug' => null]);

        // Normalise both columns to utf8mb4_unicode_ci so the foreign key constraint is compatible
        // (The meetings table was created in 2015 and may have a different collation on some servers)
        DB::statement('ALTER TABLE meetings MODIFY slug VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
        DB::statement('ALTER TABLE calendar_events MODIFY meeting_slug VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL');

        Schema::table('calendar_events', function (Blueprint $table) {
            $table->foreign('meeting_slug')
                ->references('slug')
                ->on('meetings')
                ->onUpdate('cascade')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropForeign(['meeting_slug']);
        });
    }
};
