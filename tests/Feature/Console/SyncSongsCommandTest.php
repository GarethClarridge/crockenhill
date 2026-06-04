<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Song;
use App\Models\SongAuthor;
use App\Models\SongBook;
use App\Services\Song\SongCatalogSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
            ->expectsOutputToContain('Song catalogue sync completed.');

        $this->assertDatabaseCount('songs', 3);
        $this->assertDatabaseCount('song_authors', 3);
        $this->assertDatabaseCount('song_books', 2);

        $mergedSong = Song::query()
            ->where('canonical_key', 'who am i that the highest king')
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
    public function it_reconciles_an_existing_legacy_song_row_by_title_before_creating_a_duplicate(): void
    {
        $path = $this->createSqliteWithOneSong('A New Commandment', 'a new commandment@');

        $legacySong = Song::query()->create([
            'canonical_key' => 'legacy-song-1001',
            'slug' => 'a-new-commandment',
            'title' => 'A New Commandment',
            'lyrics_xml' => '<song><lyrics><verse><lines>Legacy lyrics placeholder.</lines></verse></lyrics></song>',
        ]);

        $this->artisan('service-tracking:sync-songs', ['--path' => $path])
            ->assertExitCode(0);

        $this->assertDatabaseCount('songs', 1);

        $reconciledSong = $legacySong->fresh();

        self::assertNotNull($reconciledSong);
        $this->assertSame($legacySong->id, $reconciledSong->id);
        $this->assertSame('a new commandment', $reconciledSong->canonical_key);
        $this->assertSame('a-new-commandment', $reconciledSong->slug);
        $this->assertSame([1], $reconciledSong->import_metadata['source_song_ids'] ?? []);
    }

    #[Test]
    public function it_reconciles_an_existing_legacy_song_row_by_praise_number_and_title(): void
    {
        if (! Schema::hasColumn('songs', 'praise_number')) {
            Schema::table('songs', function (Blueprint $table): void {
                $table->string('praise_number')->nullable();
            });
        }

        $path = $this->createSqliteWithOneSong(
            'Where High The Heavenly Temple Stands #501',
            'where high the heavenly temple stands 501@ 501 where high the heavenly temple stands'
        );

        $timestamp = now();

        DB::table('songs')->insert([
            'canonical_key' => 'legacy-song-524',
            'slug' => 'where-high-the-heavenly-temple-stands',
            'title' => 'Where High The Heavenly Temple Stands',
            'lyrics_xml' => '<song><lyrics><verse><lines>Legacy lyrics placeholder.</lines></verse></lyrics></song>',
            'lyrics_plain' => null,
            'verse_order' => null,
            'copyright' => null,
            'comments' => null,
            'ccli_number' => null,
            'import_metadata' => null,
            'praise_number' => '501',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
            'deleted_at' => null,
        ]);

        $legacySong = Song::query()->firstOrFail();

        $this->artisan('service-tracking:sync-songs', ['--path' => $path])
            ->assertExitCode(0);

        $this->assertDatabaseCount('songs', 1);

        $reconciledSong = $legacySong->fresh();

        self::assertNotNull($reconciledSong);
        $this->assertSame($legacySong->id, $reconciledSong->id);
        $this->assertSame('where high the heavenly temple stands 501', $reconciledSong->canonical_key);
        $this->assertSame('where-high-the-heavenly-temple-stands', $reconciledSong->slug);
        $this->assertSame('Where High The Heavenly Temple Stands #501', $reconciledSong->title);
    }

    #[Test]
    public function it_imports_songs_with_long_copyright_notices(): void
    {
        $longCopyright = 'vv.1-3 in this version Praise Trust. v4 alt. words by Bob Kauflin '.
            'Copyright Sovereign Grace Music, a division of Sovereign Grace Churches, '.
            'with additional licensing information that exceeds the old varchar limit.';

        $path = $this->createSqliteWithOneSong(
            'Come O Fount Of Every Blessing (plus extra verse)',
            'come o fount of every blessing plus extra verse@'
        );

        $pdo = new PDO('sqlite:'.$path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $quotedCopyright = str_replace("'", "''", $longCopyright);
        $pdo->exec("UPDATE songs SET copyright = '{$quotedCopyright}' WHERE id = 1");

        $this->artisan('service-tracking:sync-songs', ['--path' => $path])
            ->assertExitCode(0);

        $this->assertDatabaseHas('songs', [
            'canonical_key' => 'come o fount of every blessing plus extra verse',
            'copyright' => $longCopyright,
        ]);
    }

    #[Test]
    public function it_respects_verse_order_when_generating_plain_lyrics_during_sync(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'song-sync-verse-order-');
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
            (1, 'Verse Order Test', NULL, '<song><lyrics><verse type=\"v\" label=\"1\">Verse line</verse><verse type=\"c\" label=\"1\">Chorus line</verse></lyrics></song>', 'c1 v1', NULL, NULL, NULL, NULL, 'verse order test@', '', NULL, '2025-01-01 10:00:00', 0)");

        $this->artisan('service-tracking:sync-songs', ['--path' => $path])
            ->assertExitCode(0);

        $song = Song::query()->where('canonical_key', 'verse order test')->firstOrFail();

        $this->assertSame("Chorus line\n\nVerse line", $song->lyrics_plain);
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
    public function dry_run_reports_projected_sync_metrics_without_writing_changes(): void
    {
        $path = $this->createSourceSqlite();

        Song::factory()->create([
            'canonical_key' => 'stand up stand up for jesus',
        ]);

        $metrics = app(SongCatalogSyncService::class)->sync(path: $path, dryRun: true);

        $this->assertSame(3, $metrics['songs_upserted']);
        $this->assertSame(2, $metrics['songs_created']);
        $this->assertSame(1, $metrics['songs_updated']);
        $this->assertSame(0, $metrics['songs_restored']);
        $this->assertSame(1, $metrics['groups_with_parse_warnings']);
        $this->assertSame(3, $metrics['song_authors_upserted']);
        $this->assertSame(2, $metrics['song_books_upserted']);
        $this->assertSame(3, $metrics['song_author_links_synced']);
        $this->assertSame(3, $metrics['song_book_links_synced']);

        $this->assertDatabaseCount('songs', 1);
        $this->assertDatabaseCount('song_authors', 0);
        $this->assertDatabaseCount('song_books', 0);
    }

    #[Test]
    public function dry_run_reports_soft_deleted_song_restores_without_restoring_in_database(): void
    {
        $path = $this->createSourceSqlite();

        $trashedSong = Song::factory()->create([
            'canonical_key' => 'stand up stand up for jesus',
        ]);
        $trashedSong->delete();

        $metrics = app(SongCatalogSyncService::class)->sync(path: $path, dryRun: true);

        $this->assertSame(1, $metrics['songs_restored']);
        $this->assertTrue($trashedSong->fresh()?->trashed() ?? false);
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
            (1, 'Who You Say I Am', NULL, '<song><lyrics><verse type=\"v\" label=\"1\">Old lyric line</verse></lyrics></song>', 'v1', NULL, NULL, '111111', NULL, 'who am i that the highest king@who you say i am', '', NULL, '2024-01-01 10:00:00', 0),
            (2, 'Who You Say I Am (Updated)', NULL, '<song><lyrics><verse type=\"v\" label=\"1\">New lyric line</verse></lyrics></song>', 'v1', NULL, NULL, '111111', NULL, 'who am i that the highest king@who you say i am', '', NULL, '2025-01-01 10:00:00', 0),
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

    private function createSqliteWithOneSong(string $title, string $searchTitle): string
    {
        $path = tempnam(sys_get_temp_dir(), 'song-sync-one-');
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

        $quotedTitle = str_replace("'", "''", $title);
        $quotedSearchTitle = str_replace("'", "''", $searchTitle);

        $pdo->exec("INSERT INTO songs (id, title, alternate_title, lyrics, verse_order, copyright, comments, ccli_number, theme_name, search_title, search_lyrics, create_date, last_modified, temporary) VALUES
            (1, '{$quotedTitle}', NULL, '<song><lyrics><verse type=\"v\" label=\"1\">Verse line</verse></lyrics></song>', 'v1', NULL, NULL, NULL, NULL, '{$quotedSearchTitle}', '', NULL, '2025-01-01 10:00:00', 0)");

        return $path;
    }
}
