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

        // Clean up openlp_search_title before adding the constraint
        DB::table('church_service_items')->update([
            'openlp_search_title' => DB::raw("NULLIF(TRIM(openlp_search_title), '')"),
        ]);

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

        DB::statement('ALTER TABLE church_service_items DROP CHECK church_service_items_openlp_search_title_format_check');
    }
};
