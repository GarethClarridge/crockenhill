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

        // 1. Ensure existing data is normalized
        DB::table('sermon_scripture_filters')->update([
            'bible_book' => DB::raw('TRIM(bible_book)'),
        ]);

        // 2. Add the CHECK constraints
        // bible_book: no leading/trailing whitespace and not empty.
        // bible_chapter: must be greater than 0.
        DB::statement("ALTER TABLE sermon_scripture_filters ADD CONSTRAINT sermon_scripture_filters_bible_book_format_check CHECK (CAST(bible_book AS CHAR CHARSET BINARY) = TRIM(bible_book) AND bible_book != '')");
        DB::statement('ALTER TABLE sermon_scripture_filters ADD CONSTRAINT sermon_scripture_filters_bible_chapter_check CHECK (bible_chapter > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE sermon_scripture_filters DROP CHECK sermon_scripture_filters_bible_book_format_check');
        DB::statement('ALTER TABLE sermon_scripture_filters DROP CHECK sermon_scripture_filters_bible_chapter_check');
    }
};
