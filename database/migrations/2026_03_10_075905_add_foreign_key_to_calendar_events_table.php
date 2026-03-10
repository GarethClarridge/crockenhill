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

        Schema::table('calendar_events', function (Blueprint $table) {
            $table->foreign('meeting_slug')
                ->references('slug')
                ->on('meetings')
                ->onUpdate('cascade')
                ->onDelete('cascade');
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
