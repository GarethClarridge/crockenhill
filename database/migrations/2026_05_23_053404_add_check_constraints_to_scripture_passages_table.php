<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('scripture_passages', function (Blueprint $table) {
            // MySQL 8.0+ supports CHECK constraints.
            // We ensure core identity and content columns are not empty.
            DB::statement("ALTER TABLE scripture_passages ADD CONSTRAINT scripture_passages_bible_id_check CHECK (bible_id <> '')");
            DB::statement("ALTER TABLE scripture_passages ADD CONSTRAINT scripture_passages_normalized_reference_check CHECK (normalized_reference <> '')");
            DB::statement("ALTER TABLE scripture_passages ADD CONSTRAINT scripture_passages_html_content_check CHECK (html_content <> '')");
            DB::statement("ALTER TABLE scripture_passages ADD CONSTRAINT scripture_passages_copyright_check CHECK (copyright <> '')");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scripture_passages', function (Blueprint $table) {
            DB::statement('ALTER TABLE scripture_passages DROP CONSTRAINT scripture_passages_bible_id_check');
            DB::statement('ALTER TABLE scripture_passages DROP CONSTRAINT scripture_passages_normalized_reference_check');
            DB::statement('ALTER TABLE scripture_passages DROP CONSTRAINT scripture_passages_html_content_check');
            DB::statement('ALTER TABLE scripture_passages DROP CONSTRAINT scripture_passages_copyright_check');
        });
    }
};
