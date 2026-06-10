<?php

declare(strict_types=1);

namespace Tests\Integration\Services\Song\Sync;

use App\Models\SongAuthor;
use App\Services\Song\Sync\SongAuthorBookSyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SongAuthorBookSyncerTest extends TestCase
{
    use RefreshDatabase;

    private SongAuthorBookSyncer $syncer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->syncer = app(SongAuthorBookSyncer::class);
    }

    #[Test]
    public function upsert_authors_maps_source_ids_to_local_ids(): void
    {
        [$idMap, $upsertedCount] = $this->syncer->upsertAuthors([
            ['id' => 10, 'display_name' => 'Writer One', 'first_name' => 'Writer', 'last_name' => 'One'],
            ['id' => 20, 'display_name' => 'Writer Two', 'first_name' => null, 'last_name' => null],
            ['id' => 30, 'display_name' => null],
        ]);

        $this->assertSame(2, $upsertedCount);
        $this->assertDatabaseCount('song_authors', 2);

        $writerOne = SongAuthor::query()->where('display_name', 'Writer One')->firstOrFail();
        $this->assertSame($writerOne->id, $idMap[10]);
        $this->assertArrayNotHasKey(30, $idMap);
    }

    #[Test]
    public function author_pivot_rows_dedupe_on_local_author_and_role(): void
    {
        $links = $this->syncer->groupLinksBySourceSongId([
            ['song_id' => 1, 'author_id' => 10, 'author_type' => 'words'],
            ['song_id' => 2, 'author_id' => 10, 'author_type' => 'words'], // duplicate via merged group row
            ['song_id' => 1, 'author_id' => 10, 'author_type' => 'music'], // same author, different role
            ['song_id' => 1, 'author_id' => 99, 'author_type' => 'words'], // unknown author — dropped
        ]);

        $rows = $this->syncer->authorPivotRows(7, [1, 2], $links, [10 => 555]);

        $this->assertSame([
            ['song_id' => 7, 'song_author_id' => 555, 'author_type' => 'words'],
            ['song_id' => 7, 'song_author_id' => 555, 'author_type' => 'music'],
        ], $rows);
    }

    #[Test]
    public function preview_maps_are_identity_maps_over_valid_source_ids(): void
    {
        [$authorMap, $authorCount] = $this->syncer->previewAuthorUpserts([
            ['id' => 10, 'display_name' => 'Writer One'],
            ['id' => null, 'display_name' => 'No Source Id'],
        ]);

        $this->assertSame([10 => 10], $authorMap);
        $this->assertSame(1, $authorCount);

        [$bookMap, $bookCount] = $this->syncer->previewBookUpserts([
            ['id' => 3, 'name' => 'Classic Hymns', 'publisher' => null],
            ['id' => 4, 'name' => null],
        ]);

        $this->assertSame([3 => 3], $bookMap);
        $this->assertSame(1, $bookCount);

        $this->assertDatabaseCount('song_authors', 0);
        $this->assertDatabaseCount('song_books', 0);
    }

    #[Test]
    public function book_pivot_rows_dedupe_on_local_book_and_entry(): void
    {
        $links = $this->syncer->groupLinksBySourceSongId([
            ['song_id' => 1, 'songbook_id' => 3, 'entry' => '10'],
            ['song_id' => 2, 'songbook_id' => 3, 'entry' => '10'], // duplicate entry across merged rows
            ['song_id' => 2, 'songbook_id' => 3, 'entry' => '11'], // distinct entry
        ]);

        $rows = $this->syncer->bookPivotRows(7, [1, 2], $links, [3 => 42]);

        $this->assertSame([
            ['song_id' => 7, 'song_book_id' => 42, 'entry' => '10'],
            ['song_id' => 7, 'song_book_id' => 42, 'entry' => '11'],
        ], $rows);
    }
}
