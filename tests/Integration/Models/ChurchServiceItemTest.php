<?php

declare(strict_types=1);

namespace Tests\Integration\Models;

use App\Enums\ChurchServiceItemSource;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\ServiceSection;
use App\Models\Song;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchServiceItemTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_casts_attributes_correctly(): void
    {
        $song = Song::factory()->create();

        $item = ChurchServiceItem::factory()->create([
            'position' => '5',
            'source' => 'email',
            'song_id' => $song->id,
            'metadata' => ['key' => 'value'],
        ]);

        $this->assertIsInt($item->position);
        $this->assertSame(5, $item->position);
        $this->assertInstanceOf(ChurchServiceItemSource::class, $item->source);
        $this->assertSame(ChurchServiceItemSource::Email, $item->source);
        $this->assertIsInt($item->song_id);
        $this->assertSame($song->id, $item->song_id);
        $this->assertIsArray($item->metadata);
        $this->assertSame(['key' => 'value'], $item->metadata);
    }

    #[Test]
    public function it_belongs_to_a_church_service(): void
    {
        $churchService = ChurchService::factory()->create();
        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
        ]);

        $this->assertInstanceOf(ChurchService::class, $item->churchService);
        $this->assertSame($churchService->id, $item->churchService->id);
    }

    #[Test]
    public function it_belongs_to_a_song(): void
    {
        $song = Song::factory()->create();
        $item = ChurchServiceItem::factory()->create([
            'song_id' => $song->id,
        ]);

        $this->assertInstanceOf(Song::class, $item->song);
        $this->assertSame($song->id, $item->song->id);
    }

    #[Test]
    public function it_has_many_service_sections(): void
    {
        $item = ChurchServiceItem::factory()->create();
        ServiceSection::factory()->count(3)->create([
            'church_service_item_id' => $item->id,
        ]);

        $this->assertCount(3, $item->serviceSections);
        $this->assertInstanceOf(ServiceSection::class, $item->serviceSections->first());
    }

    #[Test]
    public function it_uses_soft_deletes(): void
    {
        $item = ChurchServiceItem::factory()->create();
        $id = $item->id;

        $item->delete();

        $this->assertSoftDeleted('church_service_items', ['id' => $id]);
        $this->assertNotNull(ChurchServiceItem::withTrashed()->find($id)->deleted_at);
    }
}
