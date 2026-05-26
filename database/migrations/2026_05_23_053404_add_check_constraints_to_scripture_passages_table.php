<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const BIBLE_ID_CHECK = 'scripture_passages_bible_id_check';

    private const REFERENCE_CHECK = 'scripture_passages_normalized_reference_check';

    private const HTML_CHECK = 'scripture_passages_html_content_check';

    private const COPYRIGHT_CHECK = 'scripture_passages_copyright_check';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Data Cleanup: normalise existing data before applying constraints.
        // These columns come from an external Bible API and may carry leading/trailing whitespace.
        DB::table('scripture_passages')->update([
            'bible_id' => DB::raw('TRIM(bible_id)'),
            'normalized_reference' => DB::raw('TRIM(normalized_reference)'),
            'html_content' => DB::raw('TRIM(html_content)'),
            'copyright' => DB::raw('TRIM(copyright)'),
        ]);

        // Fallback for identity columns that trimmed to empty.
        DB::table('scripture_passages')
            ->where('bible_id', '')
            ->update(['bible_id' => DB::raw("CONCAT('bible-', id)")]);

        DB::table('scripture_passages')
            ->where('normalized_reference', '')
            ->update(['normalized_reference' => DB::raw("CONCAT('Passage ', id)")]);

        DB::table('scripture_passages')
            ->where('html_content', '')
            ->update(['html_content' => '<!-- empty -->']);

        DB::table('scripture_passages')
            ->where('copyright', '')
            ->update(['copyright' => 'Unknown']);

        // MySQL 8.0+ supports CHECK constraints.
        // We ensure core identity and content columns are not empty and are properly trimmed.
        DB::statement(sprintf("ALTER TABLE scripture_passages ADD CONSTRAINT %s CHECK (BINARY bible_id = TRIM(bible_id) AND bible_id <> '')", self::BIBLE_ID_CHECK));
        DB::statement(sprintf("ALTER TABLE scripture_passages ADD CONSTRAINT %s CHECK (BINARY normalized_reference = TRIM(normalized_reference) AND normalized_reference <> '')", self::REFERENCE_CHECK));
        DB::statement(sprintf("ALTER TABLE scripture_passages ADD CONSTRAINT %s CHECK (BINARY html_content = TRIM(html_content) AND html_content <> '')", self::HTML_CHECK));
        DB::statement(sprintf("ALTER TABLE scripture_passages ADD CONSTRAINT %s CHECK (BINARY copyright = TRIM(copyright) AND copyright <> '')", self::COPYRIGHT_CHECK));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement(sprintf('ALTER TABLE scripture_passages DROP CHECK %s', self::BIBLE_ID_CHECK));
        DB::statement(sprintf('ALTER TABLE scripture_passages DROP CHECK %s', self::REFERENCE_CHECK));
        DB::statement(sprintf('ALTER TABLE scripture_passages DROP CHECK %s', self::HTML_CHECK));
        DB::statement(sprintf('ALTER TABLE scripture_passages DROP CHECK %s', self::COPYRIGHT_CHECK));
    }
};
