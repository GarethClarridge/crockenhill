<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Enums\ChurchServiceItemSource;
use App\Models\ChurchServiceItem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchServiceItemSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_accepts_livestream_as_a_valid_source_value(): void
    {
        $item = ChurchServiceItem::factory()->livestream()->create();

        $source = DB::table('church_service_items')
            ->where('id', $item->id)
            ->value('source');

        $this->assertSame(ChurchServiceItemSource::Livestream->value, $source);
    }

    #[Test]
    public function it_enforces_unique_active_positions_per_service(): void
    {
        $item = ChurchServiceItem::factory()->create([
            'position' => 1,
        ]);

        $this->expectException(QueryException::class);

        DB::table('church_service_items')->insert([
            'church_service_id' => $item->church_service_id,
            'position' => 1,
            'type' => 'custom',
            'section_type' => null,
            'source' => ChurchServiceItemSource::OpenLp->value,
            'title' => 'Duplicate position',
            'source_title' => null,
            'openlp_search_title' => null,
            'song_id' => null,
            'metadata' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function a_soft_deleted_item_does_not_block_reusing_the_same_position(): void
    {
        $item = ChurchServiceItem::factory()->create([
            'position' => 1,
        ]);

        $item->delete();

        $replacement = ChurchServiceItem::factory()->create([
            'church_service_id' => $item->church_service_id,
            'position' => 1,
        ]);

        $this->assertSame(1, $replacement->position);
        $this->assertTrue($item->trashed());
    }
}
