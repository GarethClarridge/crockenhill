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
     * The column is the local side of a foreign key to service_sections, and MySQL/MariaDB
     * automatically create a backing index (church_service_items_livestream_service_section_id_foreign)
     * for every foreign key. The separately added church_service_items_livestream_service_section_id_index
     * therefore duplicates that coverage on the production engine, adding write and storage overhead for
     * no read benefit. The guard keeps this a no-op on engines (e.g. SQLite) where the duplicate was the
     * only index, or where the original migration was never applied.
     */
    public function up(): void
    {
        if (Schema::hasIndex('church_service_items', 'church_service_items_livestream_service_section_id_index')) {
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
