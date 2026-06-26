<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the redundant explicit index on church_service_items.livestream_service_section_id.
     *
     * The column is the local side of a foreign key to service_sections. When MySQL creates that
     * foreign key it only adds its own backing index (church_service_items_livestream_service_section_id_foreign)
     * if no suitable index already exists. Where that auto-created `_foreign` index is present, the separately
     * added church_service_items_livestream_service_section_id_index duplicates its coverage and can be dropped.
     *
     * Where the `_foreign` index is absent, MySQL adopted the explicit `_index` as the foreign key's sole
     * backing index, so it is not redundant and dropping it fails with errno 1553
     * ("needed in a foreign key constraint"). Guarding on the `_foreign` index keeps this a safe no-op in
     * that case, and on engines (e.g. SQLite) that do not auto-index foreign-key columns.
     */
    public function up(): void
    {
        if (
            Schema::hasIndex('church_service_items', 'church_service_items_livestream_service_section_id_index')
            && Schema::hasIndex('church_service_items', 'church_service_items_livestream_service_section_id_foreign')
        ) {
            Schema::table('church_service_items', function (Blueprint $table): void {
                $table->dropIndex('church_service_items_livestream_service_section_id_index');
            });
        }
    }

    /**
     * Reverse the migration by restoring the explicit index.
     */
    public function down(): void
    {
        if (! Schema::hasIndex('church_service_items', 'church_service_items_livestream_service_section_id_index')) {
            Schema::table('church_service_items', function (Blueprint $table): void {
                $table->index('livestream_service_section_id', 'church_service_items_livestream_service_section_id_index');
            });
        }
    }
};
