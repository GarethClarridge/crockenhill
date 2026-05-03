<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE_FORMAT_CHECK = 'church_service_items_title_format_check';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // 1. Data Cleanup: Trim existing text columns and ensure they aren't empty
        DB::table('church_service_items')->update([
            'title' => DB::raw('TRIM(title)'),
            'type' => DB::raw('TRIM(type)'),
            'source_title' => DB::raw("NULLIF(TRIM(source_title), '')"),
            'openlp_search_title' => DB::raw("NULLIF(TRIM(openlp_search_title), '')"),
        ]);

        // Ensure no empty titles/types exist before adding constraints
        DB::table('church_service_items')
            ->where('title', '')
            ->update(['title' => DB::raw("CONCAT('Item ', id)")]);

        DB::table('church_service_items')
            ->where('type', '')
            ->update(['type' => 'custom']);

        // 2. Add CHECK constraints
        // BINARY ensures exact character-for-character comparison for the TRIM check.
        DB::statement(sprintf(
            "ALTER TABLE church_service_items ADD CONSTRAINT %s CHECK (BINARY title = TRIM(title) AND title != '')",
            self::TITLE_FORMAT_CHECK
        ));

        DB::statement(
            "ALTER TABLE church_service_items ADD CONSTRAINT church_service_items_type_format_check CHECK (BINARY type = TRIM(type) AND type != '')"
        );

        DB::statement(
            "ALTER TABLE church_service_items ADD CONSTRAINT church_service_items_source_title_format_check CHECK (source_title IS NULL OR (BINARY source_title = TRIM(source_title) AND source_title != ''))"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(sprintf('ALTER TABLE church_service_items DROP CHECK %s', self::TITLE_FORMAT_CHECK));
        DB::statement('ALTER TABLE church_service_items DROP CHECK church_service_items_type_format_check');
        DB::statement('ALTER TABLE church_service_items DROP CHECK church_service_items_source_title_format_check');
    }
};
