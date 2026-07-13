<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\ChurchServiceItem;
use App\Models\Song;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SongCatalogSchemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The song-catalog tables and columns are proven to exist by the model-factory
     * inserts in the migration-idempotency and reconcile tests below (a missing column
     * would throw at insert). Indexes have no behavioural witness, so this guardrail
     * retains only the index assertions.
     */
    #[Test]
    public function it_creates_song_catalog_indexes(): void
    {
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

    private function columnType(string $tableName, string $columnName): ?string
    {
        $column = DB::selectOne(
            'SELECT COLUMN_TYPE AS column_type
                FROM information_schema.columns
                WHERE table_schema = database()
                  AND table_name = ?
                  AND column_name = ?
                LIMIT 1',
            [$tableName, $columnName],
        );

        $columnData = is_object($column)
            ? get_object_vars($column)
            : (is_array($column) ? $column : []);

        $columnType = $columnData['column_type'] ?? null;

        return is_string($columnType)
            ? strtolower($columnType)
            : null;
    }
}
