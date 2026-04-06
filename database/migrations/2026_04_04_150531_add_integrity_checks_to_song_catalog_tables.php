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

    private const SONG_AUTHOR_DISPLAY_NAME_CHECK = 'song_authors_display_name_check';

    private const SONG_BOOK_NAME_CHECK = 'song_books_name_check';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // 1. Data Cleanup: Normalize invalid data before adding constraints
            DB::table('songs')
                ->where('title', '')
                ->update(['title' => 'Untitled']);

            DB::table('songs')
                ->where('canonical_key', '')
                ->update(['canonical_key' => DB::raw("CONCAT('legacy-song-', id)")]);

            DB::table('songs')
                ->where(function ($query): void {
                    $query->where('lyrics_xml', '')
                        ->orWhereNull('lyrics_xml');
                })
                ->update(['lyrics_xml' => '<song><lyrics><verse><lines>No lyrics available.</lines></verse></lyrics></song>']);

            DB::table('song_authors')
                ->where('display_name', '')
                ->update(['display_name' => 'Unknown Author']);

            DB::table('song_books')
                ->where('name', '')
                ->update(['name' => 'Unknown Songbook']);

            // 2. Add CHECK constraints
            if (Schema::hasTable('songs')) {
                $this->addConstraintIfNotExists('songs', self::SONG_TITLE_CHECK, "title <> ''");
                $this->addConstraintIfNotExists('songs', self::SONG_CANONICAL_KEY_CHECK, "canonical_key <> ''");
                $this->addConstraintIfNotExists('songs', self::SONG_LYRICS_XML_CHECK, "lyrics_xml <> ''");
            }

            if (Schema::hasTable('song_authors')) {
                $this->addConstraintIfNotExists('song_authors', self::SONG_AUTHOR_DISPLAY_NAME_CHECK, "display_name <> ''");
            }

            if (Schema::hasTable('song_books')) {
                $this->addConstraintIfNotExists('song_books', self::SONG_BOOK_NAME_CHECK, "name <> ''");
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
                $this->dropConstraintIfExists('songs', self::SONG_TITLE_CHECK);
                $this->dropConstraintIfExists('songs', self::SONG_CANONICAL_KEY_CHECK);
                $this->dropConstraintIfExists('songs', self::SONG_LYRICS_XML_CHECK);
            }

            if (Schema::hasTable('song_authors')) {
                $this->dropConstraintIfExists('song_authors', self::SONG_AUTHOR_DISPLAY_NAME_CHECK);
            }

            if (Schema::hasTable('song_books')) {
                $this->dropConstraintIfExists('song_books', self::SONG_BOOK_NAME_CHECK);
            }
        }
    }

    private function addConstraintIfNotExists(string $table, string $constraint, string $check): void
    {
        if (! $this->constraintExists($table, $constraint)) {
            DB::statement(sprintf('ALTER TABLE %s ADD CONSTRAINT %s CHECK (%s)', $table, $constraint, $check));
        }
    }

    private function dropConstraintIfExists(string $table, string $constraint): void
    {
        if ($this->constraintExists($table, $constraint)) {
            DB::statement(sprintf('ALTER TABLE %s DROP CHECK %s', $table, $constraint));
        }
    }

    private function constraintExists(string $table, string $constraint): bool
    {
        return DB::table('information_schema.table_constraints')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('constraint_name', $constraint)
            ->exists();
    }
};
