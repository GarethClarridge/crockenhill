<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // 1. Data Cleanup: Trim existing data and convert whitespace-only to NULL for series
        DB::table('sermons')->update([
            'title' => DB::raw('TRIM(title)'),
            'series' => DB::raw("NULLIF(TRIM(series), '')"),
        ]);

        DB::table('preachers')->update([
            'name' => DB::raw('TRIM(name)'),
        ]);

        DB::table('pages')->update([
            'heading' => DB::raw('TRIM(heading)'),
        ]);

        // 2. Add CHECK constraints
        // BINARY is used to ensure exact match for the trim check.
        DB::statement("ALTER TABLE sermons ADD CONSTRAINT sermons_title_format_check CHECK (BINARY title = TRIM(title) AND title != '')");
        DB::statement("ALTER TABLE sermons ADD CONSTRAINT sermons_series_format_check CHECK (series IS NULL OR (BINARY series = TRIM(series) AND series != ''))");
        DB::statement("ALTER TABLE preachers ADD CONSTRAINT preachers_name_format_check CHECK (BINARY name = TRIM(name) AND name != '')");
        DB::statement("ALTER TABLE pages ADD CONSTRAINT pages_heading_format_check CHECK (BINARY heading = TRIM(heading) AND heading != '')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE sermons DROP CHECK sermons_title_format_check');
        DB::statement('ALTER TABLE sermons DROP CHECK sermons_series_format_check');
        DB::statement('ALTER TABLE preachers DROP CHECK preachers_name_format_check');
        DB::statement('ALTER TABLE pages DROP CHECK pages_heading_format_check');
    }
};
