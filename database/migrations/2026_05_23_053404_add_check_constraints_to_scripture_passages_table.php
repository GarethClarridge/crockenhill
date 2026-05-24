<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
        Schema::table('scripture_passages', function (Blueprint $table) {
            // MySQL 8.0+ supports CHECK constraints.
            // We ensure core identity and content columns are not empty and are properly trimmed.
            DB::statement(sprintf("ALTER TABLE scripture_passages ADD CONSTRAINT %s CHECK (BINARY bible_id = TRIM(bible_id) AND bible_id <> '')", self::BIBLE_ID_CHECK));
            DB::statement(sprintf("ALTER TABLE scripture_passages ADD CONSTRAINT %s CHECK (BINARY normalized_reference = TRIM(normalized_reference) AND normalized_reference <> '')", self::REFERENCE_CHECK));
            DB::statement(sprintf("ALTER TABLE scripture_passages ADD CONSTRAINT %s CHECK (BINARY html_content = TRIM(html_content) AND html_content <> '')", self::HTML_CHECK));
            DB::statement(sprintf("ALTER TABLE scripture_passages ADD CONSTRAINT %s CHECK (BINARY copyright = TRIM(copyright) AND copyright <> '')", self::COPYRIGHT_CHECK));
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scripture_passages', function (Blueprint $table) {
            DB::statement(sprintf('ALTER TABLE scripture_passages DROP CHECK %s', self::BIBLE_ID_CHECK));
            DB::statement(sprintf('ALTER TABLE scripture_passages DROP CHECK %s', self::REFERENCE_CHECK));
            DB::statement(sprintf('ALTER TABLE scripture_passages DROP CHECK %s', self::HTML_CHECK));
            DB::statement(sprintf('ALTER TABLE scripture_passages DROP CHECK %s', self::COPYRIGHT_CHECK));
        });
    }
};
