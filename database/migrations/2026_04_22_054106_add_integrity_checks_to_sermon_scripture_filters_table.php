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

        // 1. Clean up potential duplicates that would be created by trimming bible_book
        // before we attempt the bulk update, to avoid unique constraint violations.
        DB::statement('
            DELETE f1 FROM sermon_scripture_filters f1
            INNER JOIN sermon_scripture_filters f2
            ON f1.sermon_id = f2.sermon_id
            AND f1.bible_chapter = f2.bible_chapter
            AND TRIM(f1.bible_book) = TRIM(f2.bible_book)
            WHERE f1.id > f2.id
        ');

        // 2. Ensure existing data is normalized
        DB::table('sermon_scripture_filters')->update([
            'bible_book' => DB::raw('TRIM(bible_book)'),
        ]);

        // 3. Add the CHECK constraints
        // bible_book: no leading/trailing whitespace and not empty.
        // We use BINARY to ensure the check is case-sensitive even on case-insensitive collations.
        // bible_chapter: must be greater than 0.
        DB::statement("ALTER TABLE sermon_scripture_filters ADD CONSTRAINT sermon_scripture_filters_bible_book_format_check CHECK (BINARY bible_book = TRIM(bible_book) AND bible_book != '')");
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
