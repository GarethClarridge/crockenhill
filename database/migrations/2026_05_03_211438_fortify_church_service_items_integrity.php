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

        // 1. Data Cleanup: Trim existing data and convert empty strings to NULL for optional fields
        DB::table('church_service_items')->update([
            'title' => DB::raw('TRIM(title)'),
            'type' => DB::raw('TRIM(type)'),
            'source_title' => DB::raw("NULLIF(TRIM(source_title), '')"),
            'openlp_search_title' => DB::raw("NULLIF(TRIM(openlp_search_title), '')"),
        ]);

        // 2. Add CHECK constraints
        // BINARY is used to ensure exact match for the trim check.
        DB::statement("ALTER TABLE church_service_items ADD CONSTRAINT church_service_items_title_format_check CHECK (BINARY title = TRIM(title) AND title != '')");
        DB::statement("ALTER TABLE church_service_items ADD CONSTRAINT church_service_items_type_format_check CHECK (BINARY type = TRIM(type) AND type != '')");
        DB::statement("ALTER TABLE church_service_items ADD CONSTRAINT church_service_items_source_title_format_check CHECK (source_title IS NULL OR (BINARY source_title = TRIM(source_title) AND source_title != ''))");
        DB::statement("ALTER TABLE church_service_items ADD CONSTRAINT church_service_items_openlp_search_title_format_check CHECK (openlp_search_title IS NULL OR (BINARY openlp_search_title = TRIM(openlp_search_title) AND openlp_search_title != ''))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE church_service_items DROP CHECK church_service_items_title_format_check');
        DB::statement('ALTER TABLE church_service_items DROP CHECK church_service_items_type_format_check');
        DB::statement('ALTER TABLE church_service_items DROP CHECK church_service_items_source_title_format_check');
        DB::statement('ALTER TABLE church_service_items DROP CHECK church_service_items_openlp_search_title_format_check');
    }
};
