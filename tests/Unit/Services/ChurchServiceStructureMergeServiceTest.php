<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\ChurchServiceItemSource;
use App\Enums\SermonService;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Services\ChurchServiceStructureMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchServiceStructureMergeServiceTest extends TestCase
{
    use RefreshDatabase;

    private ChurchServiceStructureMergeService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();
        $this->service = app(ChurchServiceStructureMergeService::class);
    }

    #[Test]
    public function test_low_confidence_livestream_with_openlp_disagreement_auto_merges(): void
    {
        $churchService = $this->createLivestreamService([
            ['type' => 'songs', 'title' => 'Song A', 'confidence' => 'low'],
            ['type' => 'custom', 'title' => 'Prayer', 'section_type' => ServiceSectionType::PRAYER, 'confidence' => 'low'],
        ]);

        $incomingItems = [
            ['position' => 1, 'type' => 'songs', 'title' => 'Song B', 'source_title' => null, 'openlp_search_title' => 'song b@', 'song_id' => null, 'metadata' => null],
            ['position' => 2, 'type' => 'custom', 'title' => 'Reading', 'section_type' => ServiceSectionType::BIBLE_READING->value, 'source_title' => null, 'openlp_search_title' => null, 'song_id' => null, 'metadata' => null],
        ];

        $result = $this->service->merge($churchService, $incomingItems, ChurchServiceItemSource::OPENLP);

        $this->assertTrue($result->wasMerged);
        $this->assertFalse($result->wasStaged);
        $this->assertEmpty($result->stagedConflicts);
    }

    #[Test]
    public function test_high_confidence_livestream_with_openlp_disagreement_stages_for_review(): void
    {
        $churchService = $this->createLivestreamService([
            ['type' => 'songs', 'title' => 'Amazing Grace', 'confidence' => 'high'],
            ['type' => 'custom', 'title' => 'Sermon', 'section_type' => ServiceSectionType::SERMON, 'confidence' => 'high'],
        ]);

        $incomingItems = [
            ['position' => 1, 'type' => 'songs', 'title' => 'How Great Thou Art', 'source_title' => null, 'openlp_search_title' => 'how great@', 'song_id' => null, 'metadata' => null],
            ['position' => 2, 'type' => 'custom', 'title' => 'Opening Prayer', 'section_type' => ServiceSectionType::PRAYER->value, 'source_title' => null, 'openlp_search_title' => null, 'song_id' => null, 'metadata' => null],
        ];

        $result = $this->service->merge($churchService, $incomingItems, ChurchServiceItemSource::OPENLP);

        $this->assertFalse($result->wasMerged);
        $this->assertTrue($result->wasStaged);
        $this->assertNotEmpty($result->stagedConflicts);

        $churchService->refresh();
        $this->assertTrue($churchService->needs_review);

        $importMetadata = $churchService->import_metadata?->toArray() ?? [];
        $this->assertArrayHasKey('pending_structure_merge', $importMetadata);
        $this->assertSame('openlp', $importMetadata['pending_structure_merge']['incoming_source']);
        $this->assertNotEmpty($importMetadata['pending_structure_merge']['conflicts']);
        $this->assertNotEmpty($importMetadata['pending_structure_merge']['proposed_items']);
    }

    #[Test]
    public function test_human_approved_service_is_protected_from_livestream_reruns(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-03-23',
            'service' => SermonService::MORNING->value,
            'source' => ChurchServiceItemSource::OPENLP->value,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'OpenLP Song',
            'source' => ChurchServiceItemSource::OPENLP->value,
        ]);

        $incomingItems = [
            ['position' => 1, 'type' => 'songs', 'title' => 'Livestream Song', 'source_title' => null, 'openlp_search_title' => null, 'song_id' => null, 'metadata' => ['livestream_projection' => ['confidence_level' => 'high', 'processing_id' => 'test', 'service_section_id' => 1, 'source_segment_ids' => []]]],
        ];

        $result = $this->service->merge($churchService, $incomingItems, ChurchServiceItemSource::LIVESTREAM);

        $this->assertTrue($result->wasMerged);
        $this->assertFalse($result->wasStaged);
    }

    #[Test]
    public function test_accepted_merge_dispatches_reconciliation_via_canonical_update(): void
    {
        $churchService = $this->createLivestreamService([
            ['type' => 'songs', 'title' => 'Song A', 'confidence' => 'low'],
        ]);

        $incomingItems = [
            ['position' => 1, 'type' => 'songs', 'title' => 'Song B', 'source_title' => null, 'openlp_search_title' => 'song b@', 'song_id' => null, 'metadata' => null],
        ];

        $result = $this->service->merge($churchService, $incomingItems, ChurchServiceItemSource::OPENLP);

        $this->assertTrue($result->wasMerged);

        Event::assertDispatched(\App\Events\ChurchServiceCanonicalListChanged::class);
    }

    #[Test]
    public function test_staged_merge_does_not_dispatch_canonical_list_changed(): void
    {
        $churchService = $this->createLivestreamService([
            ['type' => 'songs', 'title' => 'Amazing Grace', 'confidence' => 'high'],
            ['type' => 'custom', 'title' => 'Sermon', 'section_type' => ServiceSectionType::SERMON, 'confidence' => 'high'],
        ]);

        $incomingItems = [
            ['position' => 1, 'type' => 'songs', 'title' => 'Different Song', 'source_title' => null, 'openlp_search_title' => 'different@', 'song_id' => null, 'metadata' => null],
            ['position' => 2, 'type' => 'custom', 'title' => 'Opening Prayer', 'section_type' => ServiceSectionType::PRAYER->value, 'source_title' => null, 'openlp_search_title' => null, 'song_id' => null, 'metadata' => null],
        ];

        $result = $this->service->merge($churchService, $incomingItems, ChurchServiceItemSource::OPENLP);

        $this->assertTrue($result->wasStaged);

        Event::assertNotDispatched(\App\Events\ChurchServiceCanonicalListChanged::class);
    }

    #[Test]
    public function test_high_confidence_livestream_with_matching_song_auto_merges(): void
    {
        $churchService = $this->createLivestreamService([
            ['type' => 'songs', 'title' => 'Amazing Grace', 'confidence' => 'high'],
        ]);

        $incomingItems = [
            ['position' => 1, 'type' => 'songs', 'title' => 'Amazing Grace', 'source_title' => null, 'openlp_search_title' => 'amazing grace@', 'song_id' => null, 'metadata' => null],
        ];

        $result = $this->service->merge($churchService, $incomingItems, ChurchServiceItemSource::OPENLP);

        $this->assertTrue($result->wasMerged);
        $this->assertFalse($result->wasStaged);
    }

    #[Test]
    public function test_staged_merge_preserves_existing_canonical_items(): void
    {
        $churchService = $this->createLivestreamService([
            ['type' => 'songs', 'title' => 'Amazing Grace', 'confidence' => 'high'],
            ['type' => 'custom', 'title' => 'Sermon', 'section_type' => ServiceSectionType::SERMON, 'confidence' => 'high'],
        ]);

        $originalItems = $churchService->items()->orderBy('position')->get();

        $incomingItems = [
            ['position' => 1, 'type' => 'songs', 'title' => 'Different Song', 'source_title' => null, 'openlp_search_title' => 'different@', 'song_id' => null, 'metadata' => null],
            ['position' => 2, 'type' => 'custom', 'title' => 'Prayer', 'section_type' => ServiceSectionType::PRAYER->value, 'source_title' => null, 'openlp_search_title' => null, 'song_id' => null, 'metadata' => null],
        ];

        $result = $this->service->merge($churchService, $incomingItems, ChurchServiceItemSource::OPENLP);

        $this->assertTrue($result->wasStaged);

        $currentItems = $churchService->fresh()->items()->orderBy('position')->get();
        $this->assertSame($originalItems->count(), $currentItems->count());
        $this->assertSame('Amazing Grace', $currentItems[0]->title);
        $this->assertSame('Sermon', $currentItems[1]->title);
    }

    #[Test]
    public function test_enrichment_of_high_confidence_item_auto_merges(): void
    {
        $song = \App\Models\Song::factory()->create(['title' => 'Amazing Grace']);

        $churchService = $this->createLivestreamService([
            ['type' => 'songs', 'title' => 'Amazing Grace', 'confidence' => 'high', 'song_id' => null],
        ]);

        $incomingItems = [
            ['position' => 1, 'type' => 'songs', 'title' => 'Amazing Grace', 'source_title' => 'Amazing Grace (My Chains Are Gone)', 'openlp_search_title' => 'amazing grace@', 'song_id' => $song->id, 'metadata' => null],
        ];

        $result = $this->service->merge($churchService, $incomingItems, ChurchServiceItemSource::OPENLP);

        $this->assertTrue($result->wasMerged);
        $this->assertFalse($result->wasStaged);
    }

    #[Test]
    public function test_non_livestream_service_bypasses_merge_planning(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-03-23',
            'service' => SermonService::MORNING->value,
            'source' => ChurchServiceItemSource::OPENLP->value,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Old Song',
            'source' => ChurchServiceItemSource::OPENLP->value,
        ]);

        $incomingItems = [
            ['position' => 1, 'type' => 'songs', 'title' => 'New Song', 'source_title' => null, 'openlp_search_title' => null, 'song_id' => null, 'metadata' => null],
        ];

        $result = $this->service->merge($churchService, $incomingItems, ChurchServiceItemSource::EMAIL);

        $this->assertTrue($result->wasMerged);
        $this->assertFalse($result->wasStaged);
    }

    #[Test]
    public function test_pending_merge_metadata_contains_proposed_items_and_classification(): void
    {
        $churchService = $this->createLivestreamService([
            ['type' => 'songs', 'title' => 'Song A', 'confidence' => 'high'],
            ['type' => 'custom', 'title' => 'Sermon', 'section_type' => ServiceSectionType::SERMON, 'confidence' => 'high'],
        ]);

        $incomingItems = [
            ['position' => 1, 'type' => 'songs', 'title' => 'Different Song', 'source_title' => null, 'openlp_search_title' => 'different@', 'song_id' => null, 'metadata' => null],
            ['position' => 2, 'type' => 'custom', 'title' => 'Prayer', 'section_type' => ServiceSectionType::PRAYER->value, 'source_title' => null, 'openlp_search_title' => null, 'song_id' => null, 'metadata' => null],
        ];

        $result = $this->service->merge($churchService, $incomingItems, ChurchServiceItemSource::OPENLP);

        $this->assertTrue($result->wasStaged);

        $importMetadata = $churchService->fresh()->import_metadata?->toArray() ?? [];
        $pending = $importMetadata['pending_structure_merge'];

        $this->assertSame('openlp', $pending['incoming_source']);
        $this->assertArrayHasKey('created_at', $pending);
        $this->assertArrayHasKey('confidence', $pending);
        $this->assertCount(2, $pending['proposed_items']);
        $this->assertArrayHasKey('classification', $pending);
        $this->assertNotEmpty($pending['classification']['review_required']);
    }

    /**
     * @param  list<array{type: string, title: string, confidence: string, section_type?: ServiceSectionType, song_id?: int|null}>  $items
     */
    private function createLivestreamService(array $items): ChurchService
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-03-23',
            'service' => SermonService::MORNING->value,
            'source' => ChurchServiceItemSource::LIVESTREAM->value,
        ]);

        foreach ($items as $index => $item) {
            $sectionType = $item['section_type'] ?? null;

            if ($sectionType === null) {
                $sectionType = match ($item['type']) {
                    'songs' => ServiceSectionType::SONG,
                    'bibles' => ServiceSectionType::BIBLE_READING,
                    default => ServiceSectionType::inferFromTitle($item['title']),
                };
            }

            ChurchServiceItem::factory()->livestream()->create([
                'church_service_id' => $churchService->id,
                'position' => $index + 1,
                'type' => $item['type'],
                'section_type' => $sectionType,
                'title' => $item['title'],
                'song_id' => $item['song_id'] ?? null,
                'metadata' => [
                    'livestream_projection' => [
                        'processing_id' => 'test-projection',
                        'service_section_id' => $index + 1,
                        'source_segment_ids' => [],
                        'confidence_level' => $item['confidence'],
                    ],
                ],
            ]);
        }

        return $churchService;
    }
}
