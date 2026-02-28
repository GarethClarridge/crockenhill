<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Song;
use App\Models\SongAuthor;
use App\Models\SongBook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SyncSongsCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function it_imports_songs_and_merges_duplicate_canonical_keys_with_authors_and_books(): void
    {
        $path = $this->createSourceSqlite();

        $this->artisan('service-tracking:sync-songs', ['--path' => $path])
            ->assertExitCode(0)
            ->expectsOutputToContain('Song catalog sync completed.');

        $this->assertDatabaseCount('songs', 3);
        $this->assertDatabaseCount('song_authors', 3);
        $this->assertDatabaseCount('song_books', 2);

        $mergedSong = Song::query()
            ->where('canonical_key', 'who am i that the highest king@who you say i am')
            ->firstOrFail();

        $this->assertSame('Who You Say I Am (Updated)', $mergedSong->title);
        $this->assertSame([1, 2], $mergedSong->import_metadata['source_song_ids'] ?? []);
        $this->assertSame(1, $mergedSong->import_metadata['duplicate_count'] ?? null);

        $writerOne = SongAuthor::query()->where('display_name', 'Writer One')->firstOrFail();
        $writerTwo = SongAuthor::query()->where('display_name', 'Writer Two')->firstOrFail();
        $classicBook = SongBook::query()->where('source_book_id', 1)->firstOrFail();
        $modernBook = SongBook::query()->where('source_book_id', 2)->firstOrFail();

        $this->assertDatabaseHas('song_author_song', [
            'song_id' => $mergedSong->id,
            'song_author_id' => $writerOne->id,
            'author_type' => 'words',
        ]);
        $this->assertDatabaseHas('song_author_song', [
            'song_id' => $mergedSong->id,
            'song_author_id' => $writerTwo->id,
            'author_type' => 'words',
        ]);
        $this->assertDatabaseHas('song_book_song', [
            'song_id' => $mergedSong->id,
            'song_book_id' => $classicBook->id,
            'entry' => '10',
        ]);
        $this->assertDatabaseHas('song_book_song', [
            'song_id' => $mergedSong->id,
            'song_book_id' => $modernBook->id,
            'entry' => '15',
        ]);
    }

    #[Test]
    public function dry_run_does_not_write_to_catalog_tables(): void
    {
        $path = $this->createSourceSqlite();

        $this->artisan('service-tracking:sync-songs', ['--path' => $path, '--dry-run' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('Dry run enabled. No database changes were written.');

        $this->assertDatabaseCount('songs', 0);
        $this->assertDatabaseCount('song_authors', 0);
        $this->assertDatabaseCount('song_books', 0);
    }

    #[Test]
    public function it_fails_with_a_clear_error_when_source_path_is_invalid(): void
    {
        $this->artisan('service-tracking:sync-songs', ['--path' => '/tmp/does-not-exist.sqlite'])
            ->assertExitCode(1)
            ->expectsOutputToContain('does not exist');
    }

    private function createSourceSqlite(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'song-sync-');
        if ($path === false) {
            self::fail('Failed to allocate temporary sqlite file.');
        }

        $this->temporaryFiles[] = $path;

        $pdo = new PDO('sqlite:'.$path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec('CREATE TABLE songs (
            id INTEGER PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            alternate_title VARCHAR(255) NULL,
            lyrics TEXT NOT NULL,
            verse_order VARCHAR(128) NULL,
            copyright VARCHAR(255) NULL,
            comments TEXT NULL,
            ccli_number VARCHAR(64) NULL,
            theme_name VARCHAR(128) NULL,
            search_title VARCHAR(255) NOT NULL,
            search_lyrics TEXT NOT NULL,
            create_date DATETIME NULL,
            last_modified DATETIME NULL,
            temporary BOOLEAN NULL
        )');
        $pdo->exec('CREATE TABLE authors (
            id INTEGER PRIMARY KEY,
            first_name VARCHAR(128) NULL,
            last_name VARCHAR(128) NULL,
            display_name VARCHAR(255) NOT NULL
        )');
        $pdo->exec('CREATE TABLE authors_songs (
            author_id INTEGER NOT NULL,
            song_id INTEGER NOT NULL,
            author_type VARCHAR(255) NOT NULL DEFAULT ""
        )');
        $pdo->exec('CREATE TABLE song_books (
            id INTEGER PRIMARY KEY,
            name VARCHAR(128) NOT NULL,
            publisher VARCHAR(128) NULL
        )');
        $pdo->exec('CREATE TABLE songs_songbooks (
            songbook_id INTEGER NOT NULL,
            song_id INTEGER NOT NULL,
            entry VARCHAR(255) NOT NULL
        )');

        $pdo->exec("INSERT INTO songs (id, title, alternate_title, lyrics, verse_order, copyright, comments, ccli_number, theme_name, search_title, search_lyrics, create_date, last_modified, temporary) VALUES
            (1, 'Who You Say I Am', NULL, '<song><lyrics><verse>Old lyric line</verse></lyrics></song>', 'v1 c1', NULL, NULL, '111111', NULL, 'who am i that the highest king@who you say i am', '', NULL, '2024-01-01 10:00:00', 0),
            (2, 'Who You Say I Am (Updated)', NULL, '<song><lyrics><verse>New lyric line</verse></lyrics></song>', 'v1 c1', NULL, NULL, '111111', NULL, 'who am i that the highest king@who you say i am', '', NULL, '2025-01-01 10:00:00', 0),
            (3, 'Stand Up Stand Up', NULL, '<song><lyrics><verse>Stand up line</verse></lyrics></song>', NULL, NULL, NULL, '222222', NULL, 'stand up stand up for jesus@', '', NULL, '2024-05-01 10:00:00', 0),
            (4, 'Broken Xml Song', NULL, '<song><lyrics><verse>Broken', NULL, NULL, NULL, '333333', NULL, 'broken xml song@', '', NULL, '2024-06-01 10:00:00', 0)");

        $pdo->exec("INSERT INTO authors (id, first_name, last_name, display_name) VALUES
            (1, 'Writer', 'One', 'Writer One'),
            (2, 'Writer', 'Two', 'Writer Two'),
            (3, 'Writer', 'Three', 'Writer Three')");
        $pdo->exec("INSERT INTO authors_songs (author_id, song_id, author_type) VALUES
            (1, 1, 'words'),
            (2, 2, 'words'),
            (3, 3, 'words')");

        $pdo->exec("INSERT INTO song_books (id, name, publisher) VALUES
            (1, 'Classic Hymns', 'Publisher A'),
            (2, 'Modern Songs', 'Publisher B')");
        $pdo->exec("INSERT INTO songs_songbooks (songbook_id, song_id, entry) VALUES
            (1, 1, '10'),
            (2, 2, '15'),
            (1, 3, '45')");

        return $path;
    }
}
