<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\ChurchServiceItem;
use App\Models\Song;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SongCatalogSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_song_catalog_tables_columns_and_indexes(): void
    {
        $this->assertTrue(Schema::hasTable('songs'));
        $this->assertTrue(Schema::hasTable('song_authors'));
        $this->assertTrue(Schema::hasTable('song_author_song'));
        $this->assertTrue(Schema::hasTable('song_books'));
        $this->assertTrue(Schema::hasTable('song_book_song'));

        $this->assertTrue(Schema::hasColumns('songs', [
            'id',
            'canonical_key',
            'title',
            'alternate_title',
            'lyrics_xml',
            'lyrics_plain',
            'verse_order',
            'copyright',
            'comments',
            'ccli_number',
            'import_metadata',
            'deleted_at',
            'created_at',
            'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('church_service_items', [
            'song_id',
        ]));

        $this->assertTrue(Schema::hasIndex('songs', 'songs_canonical_key_unique'));
        $this->assertTrue(Schema::hasIndex('songs', 'songs_ccli_number_index'));
        $this->assertTrue(Schema::hasIndex('songs', 'songs_deleted_at_index'));
        $this->assertTrue(Schema::hasIndex('church_service_items', 'church_service_items_song_id_index'));
        $this->assertTrue(Schema::hasIndex('church_service_items', 'church_service_items_type_song_id_index'));
    }

    #[Test]
    public function force_deleting_song_sets_song_id_to_null_on_church_service_item(): void
    {
        $song = Song::factory()->create();
        $item = ChurchServiceItem::factory()->create([
            'song_id' => $song->id,
        ]);

        $song->forceDelete();
        $item->refresh();

        $this->assertNull($item->song_id);
    }
}
