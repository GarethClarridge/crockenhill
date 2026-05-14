<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Models\Song;
use App\Services\SongCatalogSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class SongCatalogSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private SongCatalogSyncService $service;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SongCatalogSyncService::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    // ── Path validation ───────────────────────────────────────────────────────

    #[Test]
    public function it_throws_when_no_path_is_configured_or_provided(): void
    {
        config(['service-tracking.songs.sqlite_path' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No songs SQLite path configured');

        $this->service->sync();
    }

    #[Test]
    public function it_throws_when_the_sqlite_file_does_not_exist(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Songs SQLite file does not exist');

        $this->service->sync('/tmp/does-not-exist-'.uniqid().'.db');
    }

    #[Test]
    public function it_throws_when_required_tables_are_missing(): void
    {
        $path = $this->createEmptySqliteFile();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing required table');

        $this->service->sync($path);
    }

    // ── Dry run ───────────────────────────────────────────────────────────────

    #[Test]
    public function it_returns_metrics_without_writing_to_the_database_in_dry_run_mode(): void
    {
        $path = $this->createSqliteWithOneSong('Amazing Grace', 'amazing grace how sweet the sound');
        $songCountBeforeSync = Song::query()->count();

        $metrics = $this->service->sync($path, dryRun: true);

        $this->assertTrue($metrics['dry_run']);
        $this->assertSame(1, $metrics['source_songs']);
        $this->assertSame(1, $metrics['canonical_groups']);
        $this->assertSame(1, $metrics['songs_upserted']);
        $this->assertSame(1, $metrics['songs_created']);

        // No actual DB writes
        $this->assertSame($songCountBeforeSync, Song::query()->count());
        $this->assertDatabaseMissing('songs', ['title' => 'Amazing Grace']);
    }

    // ── Live sync ─────────────────────────────────────────────────────────────

    #[Test]
    public function it_creates_a_new_song_record_on_first_sync(): void
    {
        $path = $this->createSqliteWithOneSong('How Great Thou Art', 'how great thou art');

        $metrics = $this->service->sync($path, dryRun: false);

        $this->assertFalse($metrics['dry_run']);
        $this->assertSame(1, $metrics['songs_created']);
        $this->assertSame(0, $metrics['songs_updated']);

        $this->assertDatabaseHas('songs', ['title' => 'How Great Thou Art']);
    }

    #[Test]
    public function it_updates_an_existing_song_on_subsequent_sync(): void
    {
        $path = $this->createSqliteWithOneSong('Be Thou My Vision', 'be thou my vision');

        $this->service->sync($path, dryRun: false);

        // Sync again — same canonical key, should update
        $metrics = $this->service->sync($path, dryRun: false);

        $this->assertSame(0, $metrics['songs_created']);
        $this->assertSame(1, $metrics['songs_updated']);
        $this->assertSame(1, $metrics['songs_upserted']);
    }

    #[Test]
    public function it_groups_duplicate_songs_by_canonical_key(): void
    {
        $path = $this->createSqliteWithDuplicateSongs(
            ['title' => 'To God Be The Glory', 'search_title' => 'to god be the glory'],
            ['title' => 'To God Be the Glory (alt)', 'search_title' => 'to god be the glory'],
        );

        $metrics = $this->service->sync($path, dryRun: true);

        $this->assertSame(2, $metrics['source_songs']);
        $this->assertSame(1, $metrics['canonical_groups']);
        $this->assertSame(1, $metrics['duplicate_groups']);
        $this->assertSame(1, $metrics['duplicate_rows']);
        $this->assertSame(1, $metrics['songs_upserted']);
    }

    #[Test]
    public function it_restores_a_soft_deleted_song_when_reimported(): void
    {
        $path = $this->createSqliteWithOneSong('Great Is Thy Faithfulness', 'great is thy faithfulness');

        $this->service->sync($path, dryRun: false);

        Song::query()->where('title', 'Great Is Thy Faithfulness')->delete();
        $this->assertSoftDeleted('songs', ['title' => 'Great Is Thy Faithfulness']);

        $metrics = $this->service->sync($path, dryRun: false);

        $this->assertSame(1, $metrics['songs_restored']);
        $this->assertDatabaseHas('songs', ['title' => 'Great Is Thy Faithfulness', 'deleted_at' => null]);
    }

    #[Test]
    public function it_supports_multi_role_authors_for_a_single_song(): void
    {
        $path = $this->createSqliteWithMultiRoleAuthor('In Christ Alone', 'in christ alone');

        $metrics = $this->service->sync($path, dryRun: false);

        $this->assertSame(2, $metrics['song_author_links_synced']);

        $song = Song::query()->where('title', 'In Christ Alone')->firstOrFail();
        $this->assertCount(2, $song->authors);
        $this->assertDatabaseHas('song_author_song', [
            'song_id' => $song->id,
            'author_type' => 'Lyricist',
        ]);
        $this->assertDatabaseHas('song_author_song', [
            'song_id' => $song->id,
            'author_type' => 'Composer',
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function createEmptySqliteFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'songs_test_').'.db';
        $this->tempFiles[] = $path;
        new \PDO('sqlite:'.$path);

        return $path;
    }

    private function createSqliteWithOneSong(string $title, string $searchTitle): string
    {
        $path = tempnam(sys_get_temp_dir(), 'songs_test_').'.db';
        $this->tempFiles[] = $path;

        $pdo = new \PDO('sqlite:'.$path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->createSqliteSchema($pdo);

        $pdo->exec("INSERT INTO songs (id, title, alternate_title, lyrics, verse_order, copyright, comments, ccli_number, search_title, last_modified)
            VALUES (1, '$title', NULL, '', NULL, NULL, NULL, NULL, '$searchTitle', '2026-01-01 10:00:00')");

        return $path;
    }

    private function createSqliteWithMultiRoleAuthor(string $title, string $searchTitle): string
    {
        $path = tempnam(sys_get_temp_dir(), 'songs_test_').'.db';
        $this->tempFiles[] = $path;

        $pdo = new \PDO('sqlite:'.$path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->createSqliteSchema($pdo);

        $pdo->exec("INSERT INTO songs (id, title, alternate_title, lyrics, verse_order, copyright, comments, ccli_number, search_title, last_modified)
            VALUES (1, '$title', NULL, '', NULL, NULL, NULL, NULL, '$searchTitle', '2026-01-01 10:00:00')");

        $pdo->exec("INSERT INTO authors (id, display_name) VALUES (1, 'Stuart Townend')");

        $pdo->exec("INSERT INTO authors_songs (song_id, author_id, author_type) VALUES (1, 1, 'Lyricist')");
        $pdo->exec("INSERT INTO authors_songs (song_id, author_id, author_type) VALUES (1, 1, 'Composer')");

        return $path;
    }

    /**
     * @param  array{title: string, search_title: string}  $first
     * @param  array{title: string, search_title: string}  $second
     */
    private function createSqliteWithDuplicateSongs(array $first, array $second): string
    {
        $path = tempnam(sys_get_temp_dir(), 'songs_test_').'.db';
        $this->tempFiles[] = $path;

        $pdo = new \PDO('sqlite:'.$path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->createSqliteSchema($pdo);

        $pdo->exec("INSERT INTO songs (id, title, alternate_title, lyrics, verse_order, copyright, comments, ccli_number, search_title, last_modified)
            VALUES (1, '{$first['title']}', NULL, '', NULL, NULL, NULL, NULL, '{$first['search_title']}', '2026-01-01 10:00:00')");

        $pdo->exec("INSERT INTO songs (id, title, alternate_title, lyrics, verse_order, copyright, comments, ccli_number, search_title, last_modified)
            VALUES (2, '{$second['title']}', NULL, '', NULL, NULL, NULL, NULL, '{$second['search_title']}', '2026-01-01 09:00:00')");

        return $path;
    }

    private function createSqliteSchema(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE songs (
            id INTEGER PRIMARY KEY,
            title TEXT,
            alternate_title TEXT,
            lyrics TEXT,
            verse_order TEXT,
            copyright TEXT,
            comments TEXT,
            ccli_number TEXT,
            search_title TEXT,
            last_modified TEXT
        )');

        $pdo->exec('CREATE TABLE authors (
            id INTEGER PRIMARY KEY,
            display_name TEXT,
            first_name TEXT,
            last_name TEXT
        )');

        $pdo->exec('CREATE TABLE authors_songs (
            song_id INTEGER,
            author_id INTEGER,
            author_type TEXT
        )');

        $pdo->exec('CREATE TABLE song_books (
            id INTEGER PRIMARY KEY,
            name TEXT,
            publisher TEXT
        )');

        $pdo->exec('CREATE TABLE songs_songbooks (
            song_id INTEGER,
            songbook_id INTEGER,
            entry TEXT
        )');
    }
}
