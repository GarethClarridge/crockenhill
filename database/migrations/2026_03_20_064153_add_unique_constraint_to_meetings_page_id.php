<?php

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
        // Add a specifically named unique index for the page_id column.
        // This enforces the 1-to-1 relationship between Pages and Meetings.
        Schema::table('meetings', function (Blueprint $table) {
            $table->unique('page_id', 'meetings_page_id_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropUnique('meetings_page_id_unique');
        });
    }
};
