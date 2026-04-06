<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SONGS_NOT_EMPTY_CHECK = 'songs_not_empty_check';
    private const SONG_AUTHORS_NOT_EMPTY_CHECK = 'song_authors_not_empty_check';
    private const SONG_BOOKS_NOT_EMPTY_CHECK = 'song_books_not_empty_check';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('songs')) {
            return;
        }

        // 1. Data Cleanup for songs
        // Ensure lyrics_xml is never empty
        DB::table('songs')
            ->whereRaw("lyrics_xml = '' OR lyrics_xml IS NULL")
            ->update(['lyrics_xml' => '<song></song>']);

        // Ensure title is never empty (whitespace only counts as empty for our purposes)
        DB::table('songs')
            ->whereRaw("TRIM(title) = '' OR title IS NULL")
            ->update(['title' => DB::raw("CONCAT('Song ', id)")]);

        // Ensure canonical_key is never empty and unique
        DB::table('songs')
            ->whereRaw("TRIM(canonical_key) = '' OR canonical_key IS NULL")
            ->update(['canonical_key' => DB::raw("CONCAT('legacy-song-', id)")]);

        // 2. Data Cleanup for song_authors
        DB::table('song_authors')
            ->whereRaw("TRIM(display_name) = '' OR display_name IS NULL")
            ->update(['display_name' => DB::raw("CONCAT('Author ', id)")]);

        // 3. Data Cleanup for song_books
        DB::table('song_books')
            ->whereRaw("TRIM(name) = '' OR name IS NULL")
            ->update(['name' => DB::raw("CONCAT('Book ', id)")]);

        // 4. Add CHECK constraints (MySQL only)
        if (DB::getDriverName() === 'mysql') {
            DB::statement(sprintf(
                "ALTER TABLE songs ADD CONSTRAINT %s CHECK (TRIM(title) <> '' AND TRIM(canonical_key) <> '' AND lyrics_xml <> '')",
                self::SONGS_NOT_EMPTY_CHECK
            ));

            DB::statement(sprintf(
                "ALTER TABLE song_authors ADD CONSTRAINT %s CHECK (TRIM(display_name) <> '')",
                self::SONG_AUTHORS_NOT_EMPTY_CHECK
            ));

            DB::statement(sprintf(
                "ALTER TABLE song_books ADD CONSTRAINT %s CHECK (TRIM(name) <> '')",
                self::SONG_BOOKS_NOT_EMPTY_CHECK
            ));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement(sprintf('ALTER TABLE songs DROP CHECK IF EXISTS %s', self::SONGS_NOT_EMPTY_CHECK));
            DB::statement(sprintf('ALTER TABLE song_authors DROP CHECK IF EXISTS %s', self::SONG_AUTHORS_NOT_EMPTY_CHECK));
            DB::statement(sprintf('ALTER TABLE song_books DROP CHECK IF EXISTS %s', self::SONG_BOOKS_NOT_EMPTY_CHECK));
        }
    }
};
