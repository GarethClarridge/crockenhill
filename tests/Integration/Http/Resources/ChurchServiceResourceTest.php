<?php

declare(strict_types=1);

namespace Tests\Integration\Http\Resources;

use App\Enums\SermonService;
use App\Http\Resources\ChurchServiceResource;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchServiceResourceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_transforms_to_array_with_all_expected_keys(): void
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-04-06',
            'service' => SermonService::Morning,
            'needs_review' => false,
            'summary' => 'A service summary.',
            'notices' => [['title' => 'Holiday club', 'details' => 'Registration opens next week.']],
            'chapter_markers' => [['title' => 'Sermon', 'start_time' => 60.0, 'end_time' => 1800.0]],
            'import_metadata' => null,
        ]);

        $array = (new ChurchServiceResource($service))->toArray(request());

        $this->assertSame($service->id, $array['id']);
        $this->assertSame('2026-04-06', $array['date']);
        $this->assertSame(SermonService::Morning->value, $array['service']);
        $this->assertArrayHasKey('source', $array);
        $this->assertArrayHasKey('original_filename', $array);
        $this->assertFalse($array['needs_review']);
        $this->assertSame('A service summary.', $array['summary']);
        $this->assertSame([['title' => 'Holiday club', 'details' => 'Registration opens next week.']], $array['notices']);
        $this->assertSame([['title' => 'Sermon', 'start_time' => 60, 'end_time' => 1800]], $array['chapter_markers']);
        $this->assertArrayHasKey('import_metadata', $array);
        $this->assertArrayHasKey('items', $array);
        $this->assertArrayHasKey('created_at', $array);
        $this->assertArrayHasKey('updated_at', $array);
    }

    #[Test]
    public function it_formats_date_as_y_m_d_regardless_of_timezone(): void
    {
        $service = ChurchService::factory()->create(['date' => '2026-12-25']);

        $array = (new ChurchServiceResource($service))->toArray(request());

        $this->assertSame('2026-12-25', $array['date']);
    }

    #[Test]
    public function it_serialises_service_enum_as_its_value(): void
    {
        $service = ChurchService::factory()->create(['service' => SermonService::Evening]);

        $array = (new ChurchServiceResource($service))->toArray(request());

        $this->assertSame(SermonService::Evening->value, $array['service']);
        $this->assertIsString($array['service']);
    }

    #[Test]
    public function it_omits_items_key_in_resolved_json_when_relation_is_not_loaded(): void
    {
        $service = ChurchService::factory()->create();
        ChurchServiceItem::factory()->count(2)->create(['church_service_id' => $service->id]);

        $service->unsetRelation('items');

        $resolved = (new ChurchServiceResource($service))->resolve();

        $this->assertArrayNotHasKey('items', $resolved);
    }

    #[Test]
    public function it_embeds_items_in_position_order_when_relation_is_loaded(): void
    {
        $service = ChurchService::factory()->create();
        ChurchServiceItem::factory()->create(['church_service_id' => $service->id, 'position' => 3, 'type' => 'songs']);
        ChurchServiceItem::factory()->create(['church_service_id' => $service->id, 'position' => 1, 'type' => 'bibles']);
        ChurchServiceItem::factory()->create(['church_service_id' => $service->id, 'position' => 2, 'type' => 'custom']);

        $service->load('items');

        $array = (new ChurchServiceResource($service))->toArray(request());

        $items = collect($array['items'])->map(fn ($i) => is_array($i) ? $i : $i->resolve())->values()->all();

        $this->assertCount(3, $items);

        $positions = array_column($items, 'position');
        $this->assertSame([1, 2, 3], array_values($positions));
    }

    #[Test]
    public function it_serialises_timestamps_as_iso_strings(): void
    {
        $service = ChurchService::factory()->create();

        $array = (new ChurchServiceResource($service))->toArray(request());

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $array['created_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $array['updated_at']);
    }
}
