<?php

declare(strict_types=1);

namespace Tests\Integration\Http\Resources;

use App\Http\Resources\ChurchServiceItemResource;
use App\Models\ChurchServiceItem;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchServiceItemResourceTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_transforms_to_array_with_all_expected_keys(): void
    {
        $item = ChurchServiceItem::factory()->create([
            'position' => 2,
            'type' => 'songs',
            'title' => 'Amazing Grace',
            'source_title' => 'Amazing Grace (Hymn)',
            'openlp_search_title' => 'amazing grace@',
            'song_id' => null,
            'metadata' => null,
        ]);

        $array = (new ChurchServiceItemResource($item))->toArray(request());

        $this->assertSame($item->id, $array['id']);
        $this->assertSame(2, $array['position']);
        $this->assertSame('songs', $array['type']);
        $this->assertSame('Amazing Grace', $array['title']);
        $this->assertSame('Amazing Grace (Hymn)', $array['source_title']);
        $this->assertSame('amazing grace@', $array['openlp_search_title']);
        $this->assertNull($array['song_id']);
        $this->assertNull($array['metadata']);
        $this->assertArrayHasKey('created_at', $array);
        $this->assertArrayHasKey('updated_at', $array);
    }

    #[Test]
    public function it_serialises_timestamps_as_iso_strings(): void
    {
        $item = ChurchServiceItem::factory()->create();

        $array = (new ChurchServiceItemResource($item))->toArray(request());

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $array['created_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $array['updated_at']);
    }

    #[Test]
    public function it_includes_metadata_when_present(): void
    {
        $metadata = ['livestream_projection' => ['processing_id' => 'test-id', 'confidence_level' => 'high']];

        $item = ChurchServiceItem::factory()->create(['metadata' => $metadata]);

        $array = (new ChurchServiceItemResource($item))->toArray(request());

        $this->assertSame($metadata, $array['metadata']);
    }
}
