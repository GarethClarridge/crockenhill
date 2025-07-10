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
        // Sermons table indexes
        Schema::table('sermons', function (Blueprint $table) {
            $table->index(['date', 'service'], 'sermons_date_service_index');
            $table->index('preacher', 'sermons_preacher_index');
            $table->index('series', 'sermons_series_index');
            $table->unique('slug', 'sermons_slug_unique');
        });

        // Pages table indexes
        Schema::table('pages', function (Blueprint $table) {
            $table->unique('slug', 'pages_slug_unique');
            $table->index('area', 'pages_area_index');
        });

        // Meetings table indexes
        Schema::table('meetings', function (Blueprint $table) {
            $table->unique('slug', 'meetings_slug_unique');
            $table->index(['type', 'day'], 'meetings_type_day_index');
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
};
