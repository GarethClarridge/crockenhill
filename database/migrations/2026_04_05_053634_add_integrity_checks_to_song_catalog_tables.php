<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SONG_TITLE_CHECK = 'songs_title_check';

    private const SONG_CANONICAL_KEY_CHECK = 'songs_canonical_key_check';

    private const SONG_LYRICS_XML_CHECK = 'songs_lyrics_xml_check';

    private const AUTHOR_DISPLAY_NAME_CHECK = 'song_authors_display_name_check';

    private const BOOK_NAME_CHECK = 'song_books_name_check';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->cleanupData();

        if (DB::getDriverName() === 'mysql') {
            // 1. Songs table constraints
            if (Schema::hasTable('songs')) {
                DB::statement(sprintf(
                    "ALTER TABLE songs ADD CONSTRAINT %s CHECK (title <> '')",
                    self::SONG_TITLE_CHECK
                ));
                DB::statement(sprintf(
                    "ALTER TABLE songs ADD CONSTRAINT %s CHECK (canonical_key <> '')",
                    self::SONG_CANONICAL_KEY_CHECK
                ));
                DB::statement(sprintf(
                    "ALTER TABLE songs ADD CONSTRAINT %s CHECK (lyrics_xml <> '')",
                    self::SONG_LYRICS_XML_CHECK
                ));
            }

            // 2. Song Authors table constraints
            if (Schema::hasTable('song_authors')) {
                DB::statement(sprintf(
                    "ALTER TABLE song_authors ADD CONSTRAINT %s CHECK (display_name <> '')",
                    self::AUTHOR_DISPLAY_NAME_CHECK
                ));
            }

            // 3. Song Books table constraints
            if (Schema::hasTable('song_books')) {
                DB::statement(sprintf(
                    "ALTER TABLE song_books ADD CONSTRAINT %s CHECK (name <> '')",
                    self::BOOK_NAME_CHECK
                ));
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            if (Schema::hasTable('songs')) {
                DB::statement(sprintf('ALTER TABLE songs DROP CHECK %s', self::SONG_TITLE_CHECK));
                DB::statement(sprintf('ALTER TABLE songs DROP CHECK %s', self::SONG_CANONICAL_KEY_CHECK));
                DB::statement(sprintf('ALTER TABLE songs DROP CHECK %s', self::SONG_LYRICS_XML_CHECK));
            }

            if (Schema::hasTable('song_authors')) {
                DB::statement(sprintf('ALTER TABLE song_authors DROP CHECK %s', self::AUTHOR_DISPLAY_NAME_CHECK));
            }

            if (Schema::hasTable('song_books')) {
                DB::statement(sprintf('ALTER TABLE song_books DROP CHECK %s', self::BOOK_NAME_CHECK));
            }
        }
    }

    /**
     * Cleanup invalid data before adding constraints.
     */
    private function cleanupData(): void
    {
        if (Schema::hasTable('songs')) {
            DB::table('songs')
                ->where('title', '')
                ->update(['title' => 'Untitled']);

            DB::table('songs')
                ->where('canonical_key', '')
                ->update(['canonical_key' => DB::raw('CONCAT("legacy-song-", id)')]);

            DB::table('songs')
                ->where('lyrics_xml', '')
                ->update(['lyrics_xml' => '<song></song>']);
        }

        if (Schema::hasTable('song_authors')) {
            DB::table('song_authors')
                ->where('display_name', '')
                ->update(['display_name' => DB::raw('CONCAT("Unknown Author ", id)')]);
        }

        if (Schema::hasTable('song_books')) {
            DB::table('song_books')
                ->where('name', '')
                ->update(['name' => DB::raw('CONCAT("Unknown Book ", id)')]);
        }
    }
};
