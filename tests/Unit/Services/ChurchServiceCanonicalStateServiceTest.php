<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\ChurchServiceItemSource;
use App\Enums\SermonService;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Services\ChurchServiceCanonicalStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchServiceCanonicalStateServiceTest extends TestCase
{
    use RefreshDatabase;

    private ChurchServiceCanonicalStateService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ChurchServiceCanonicalStateService::class);
    }

    // ── Snapshot ──────────────────────────────────────────────────────────────

    #[Test]
    public function it_returns_an_empty_array_for_a_null_church_service(): void
    {
        $result = $this->service->snapshot(null);

        $this->assertSame([], $result);
    }

    #[Test]
    public function it_returns_an_empty_array_for_a_service_with_no_items(): void
    {
        $churchService = ChurchService::factory()->create(['service' => SermonService::Morning]);

        $result = $this->service->snapshot($churchService);

        $this->assertSame([], $result);
    }

    #[Test]
    public function it_snapshots_a_service_item_with_all_expected_keys(): void
    {
        $churchService = ChurchService::factory()->create(['service' => SermonService::Morning]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'source' => ChurchServiceItemSource::OpenLp->value,
            'title' => 'Great is thy faithfulness',
        ]);

        $result = $this->service->snapshot($churchService->load('items'));

        $this->assertCount(1, $result);

        $expectedKeys = ['id', 'position', 'type', 'section_type', 'source', 'title', 'source_title', 'openlp_search_title', 'song_id', 'metadata'];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $result[0]);
        }

        $this->assertSame('Great is thy faithfulness', $result[0]['title']);
        $this->assertSame(1, $result[0]['position']);
    }

    #[Test]
    public function it_excludes_section_type_from_item_metadata_in_snapshot(): void
    {
        $churchService = ChurchService::factory()->create(['service' => SermonService::Morning]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'metadata' => ['section_type' => 'song', 'extra_key' => 'value'],
        ]);

        $result = $this->service->snapshot($churchService->load('items'));

        $this->assertArrayNotHasKey('section_type', $result[0]['metadata'] ?? []);
        $this->assertSame('value', $result[0]['metadata']['extra_key']);
    }

    #[Test]
    public function it_returns_null_for_metadata_when_only_section_type_was_set(): void
    {
        $churchService = ChurchService::factory()->create(['service' => SermonService::Morning]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'metadata' => ['section_type' => 'song'],
        ]);

        $result = $this->service->snapshot($churchService->load('items'));

        $this->assertNull($result[0]['metadata']);
    }

    #[Test]
    public function it_normalises_associative_metadata_keys_by_sorting(): void
    {
        $churchService = ChurchService::factory()->create(['service' => SermonService::Morning]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'metadata' => ['z_key' => 1, 'a_key' => 2],
        ]);

        $result = $this->service->snapshot($churchService->load('items'));

        $metadataKeys = array_keys($result[0]['metadata']);
        $this->assertSame(['a_key', 'z_key'], $metadataKeys);
    }

    // ── Diff ──────────────────────────────────────────────────────────────────

    #[Test]
    public function it_returns_empty_diff_when_nothing_has_changed(): void
    {
        $churchService = ChurchService::factory()->create(['service' => SermonService::Morning]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Before the throne',
        ]);

        $before = $this->service->snapshot($churchService->load('items'));
        $after = $this->service->snapshot($churchService->fresh()->load('items'));

        $diff = $this->service->diff($before, $after);

        $this->assertSame([], $diff);
    }

    #[Test]
    public function it_detects_an_added_item(): void
    {
        $churchService = ChurchService::factory()->create(['service' => SermonService::Morning]);

        $before = $this->service->snapshot($churchService->load('items'));

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Amazing Grace',
        ]);

        $after = $this->service->snapshot($churchService->fresh()->load('items'));

        $diff = $this->service->diff($before, $after);

        $this->assertCount(1, $diff);
        $this->assertSame('added_item', $diff[0]['type']);
        $this->assertSame('Amazing Grace', $diff[0]['item']['title']);
    }

    #[Test]
    public function it_detects_a_removed_item(): void
    {
        $churchService = ChurchService::factory()->create(['service' => SermonService::Morning]);

        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'How Great Thou Art',
        ]);

        $before = $this->service->snapshot($churchService->load('items'));

        $item->delete();

        $after = $this->service->snapshot($churchService->fresh()->load('items'));

        $diff = $this->service->diff($before, $after);

        $this->assertCount(1, $diff);
        $this->assertSame('removed_item', $diff[0]['type']);
        $this->assertSame('How Great Thou Art', $diff[0]['item']['title']);
    }

    #[Test]
    public function it_detects_an_updated_item_field(): void
    {
        $churchService = ChurchService::factory()->create(['service' => SermonService::Morning]);

        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Be thou my vision',
        ]);

        $before = $this->service->snapshot($churchService->load('items'));

        $item->update(['title' => 'Be Thou My Vision (updated)']);

        $after = $this->service->snapshot($churchService->fresh()->load('items'));

        $diff = $this->service->diff($before, $after);

        $this->assertCount(1, $diff);
        $this->assertSame('updated_item', $diff[0]['type']);

        $titleChange = collect($diff[0]['fields'])->firstWhere('field', 'title');
        $this->assertNotNull($titleChange);
        $this->assertSame('Be thou my vision', $titleChange['before']);
        $this->assertSame('Be Thou My Vision (updated)', $titleChange['after']);
    }

    #[Test]
    public function it_reports_all_changed_fields_in_an_updated_item(): void
    {
        $churchService = ChurchService::factory()->create(['service' => SermonService::Morning]);

        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Original title',
        ]);

        $before = $this->service->snapshot($churchService->load('items'));

        $item->update(['title' => 'New title', 'position' => 2]);

        $after = $this->service->snapshot($churchService->fresh()->load('items'));

        $diff = $this->service->diff($before, $after);

        $this->assertCount(1, $diff);
        $changedFields = array_column($diff[0]['fields'], 'field');
        $this->assertContains('title', $changedFields);
        $this->assertContains('position', $changedFields);
    }
}
