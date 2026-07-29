<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Enums\ChurchServiceItemSource;
use App\Enums\SermonService;
use App\Enums\ServiceSectionType;
use App\Events\ChurchServiceCanonicalListChanged;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Services\ChurchService\ChurchServiceItemSyncService;
use App\Services\ChurchService\ChurchServiceStructureMergeService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
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
            ['type' => 'custom', 'title' => 'Prayer', 'section_type' => ServiceSectionType::Prayer, 'confidence' => 'low'],
        ]);

        $incomingItems = [
            ['position' => 1, 'type' => 'songs', 'title' => 'Song B', 'source_title' => null, 'openlp_search_title' => 'song b@', 'song_id' => null, 'metadata' => null],
            ['position' => 2, 'type' => 'custom', 'title' => 'Reading', 'section_type' => ServiceSectionType::BibleReading->value, 'source_title' => null, 'openlp_search_title' => null, 'song_id' => null, 'metadata' => null],
        ];

        $result = $this->service->merge($churchService, $incomingItems, ChurchServiceItemSource::OpenLp);

        $this->assertTrue($result->wasMerged);
        $this->assertFalse($result->wasStaged);
        $this->assertEmpty($result->stagedConflicts);
    }

    #[Test]
    public function test_high_confidence_livestream_with_openlp_disagreement_stages_for_review(): void
    {
        $churchService = $this->createLivestreamService([
            ['type' => 'songs', 'title' => 'Amazing Grace', 'confidence' => 'high'],
            ['type' => 'custom', 'title' => 'Sermon', 'section_type' => ServiceSectionType::Sermon, 'confidence' => 'high'],
        ]);

        $incomingItems = [
            ['position' => 1, 'type' => 'songs', 'title' => 'How Great Thou Art', 'source_title' => null, 'openlp_search_title' => 'how great@', 'song_id' => null, 'metadata' => null],
            ['position' => 2, 'type' => 'custom', 'title' => 'Opening Prayer', 'section_type' => ServiceSectionType::Prayer->value, 'source_title' => null, 'openlp_search_title' => null, 'song_id' => null, 'metadata' => null],
        ];

        $result = $this->service->merge($churchService, $incomingItems, ChurchServiceItemSource::OpenLp);

        $this->assertFalse($result->wasMerged);
        $this->assertTrue($result->wasStaged);
        $this->assertNotEmpty($result->stagedConflicts);

        $churchService->refresh();
        $this->assertTrue($churchService->needs_review);

        $this->assertSame('openlp', $churchService->pending_structure_merge_source);
        $importMetadata = $churchService->import_metadata?->toArray() ?? [];
        $this->assertArrayHasKey('pending_structure_merge', $importMetadata);
        $this->assertArrayNotHasKey('incoming_source', $importMetadata['pending_structure_merge']);
        $this->assertNotEmpty($importMetadata['pending_structure_merge']['conflicts']);
        $this->assertNotEmpty($importMetadata['pending_structure_merge']['proposed_items']);
    }

    #[Test]
    public function test_human_approved_service_is_protected_from_livestream_reruns(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-03-23',
            'service' => SermonService::Morning->value,
            'source' => ChurchServiceItemSource::OpenLp->value,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'OpenLP Song',
            'source' => ChurchServiceItemSource::OpenLp->value,
        ]);

        $incomingItems = [
            ['position' => 1, 'type' => 'songs', 'title' => 'Livestream Song', 'source_title' => null, 'openlp_search_title' => null, 'song_id' => null, 'metadata' => ['livestream_projection' => ['confidence_level' => 'high', 'source_segment_ids' => []]]],
        ];

        $result = $this->service->merge($churchService, $incomingItems, ChurchServiceItemSource::Livestream);

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

        $result = $this->service->merge($churchService, $incomingItems, ChurchServiceItemSource::OpenLp);

        $this->assertTrue($result->wasMerged);

        Event::assertDispatched(ChurchServiceCanonicalListChanged::class);
    }

    #[Test]
    public function test_staged_merge_does_not_dispatch_canonical_list_changed(): void
    {
        $churchService = $this->createLivestreamService([
            ['type' => 'songs', 'title' => 'Amazing Grace', 'confidence' => 'high'],
            ['type' => 'custom', 'title' => 'Sermon', 'section_type' => ServiceSectionType::Sermon, 'confidence' => 'high'],
        ]);

        $incomingItems = [
            ['position' => 1, 'type' => 'songs', 'title' => 'Different Song', 'source_title' => null, 'openlp_search_title' => 'different@', 'song_id' => null, 'metadata' => null],
            ['position' => 2, 'type' => 'custom', 'title' => 'Opening Prayer', 'section_type' => ServiceSectionType::Prayer->value, 'source_title' => null, 'openlp_search_title' => null, 'song_id' => null, 'metadata' => null],
        ];

        $result = $this->service->merge($churchService, $incomingItems, ChurchServiceItemSource::OpenLp);

        $this->assertTrue($result->wasStaged);

        Event::assertNotDispatched(ChurchServiceCanonicalListChanged::class);
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

        $result = $this->service->merge($churchService, $incomingItems, ChurchServiceItemSource::OpenLp);

        $this->assertTrue($result->wasMerged);
        $this->assertFalse($result->wasStaged);
    }

    #[Test]
    public function test_staged_merge_preserves_existing_canonical_items(): void
    {
        $churchService = $this->createLivestreamService([
            ['type' => 'songs', 'title' => 'Amazing Grace', 'confidence' => 'high'],
            ['type' => 'custom', 'title' => 'Sermon', 'section_type' => ServiceSectionType::Sermon, 'confidence' => 'high'],
        ]);

        $originalItems = $churchService->items()->orderBy('position')->get();

        $incomingItems = [
            ['position' => 1, 'type' => 'songs', 'title' => 'Different Song', 'source_title' => null, 'openlp_search_title' => 'different@', 'song_id' => null, 'metadata' => null],
            ['position' => 2, 'type' => 'custom', 'title' => 'Prayer', 'section_type' => ServiceSectionType::Prayer->value, 'source_title' => null, 'openlp_search_title' => null, 'song_id' => null, 'metadata' => null],
        ];

        $result = $this->service->merge($churchService, $incomingItems, ChurchServiceItemSource::OpenLp);

        $this->assertTrue($result->wasStaged);

        $currentItems = $churchService->fresh()->items()->orderBy('position')->get();
        $this->assertSame($originalItems->count(), $currentItems->count());
        $this->assertSame('Amazing Grace', $currentItems[0]->title);
        $this->assertSame('Sermon', $currentItems[1]->title);
    }

    #[Test]
    public function test_enrichment_of_high_confidence_item_auto_merges(): void
    {
        $song = Song::factory()->create(['title' => 'Amazing Grace']);

        $churchService = $this->createLivestreamService([
            ['type' => 'songs', 'title' => 'Amazing Grace', 'confidence' => 'high', 'song_id' => null],
        ]);

        $incomingItems = [
            ['position' => 1, 'type' => 'songs', 'title' => 'Amazing Grace', 'source_title' => 'Amazing Grace (My Chains Are Gone)', 'openlp_search_title' => 'amazing grace@', 'song_id' => $song->id, 'metadata' => null],
        ];

        $result = $this->service->merge($churchService, $incomingItems, ChurchServiceItemSource::OpenLp);

        $this->assertTrue($result->wasMerged);
        $this->assertFalse($result->wasStaged);
    }

    #[Test]
    public function test_non_livestream_service_bypasses_merge_planning(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-03-23',
            'service' => SermonService::Morning->value,
            'source' => ChurchServiceItemSource::OpenLp->value,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Old Song',
            'source' => ChurchServiceItemSource::OpenLp->value,
        ]);

        $incomingItems = [
            ['position' => 1, 'type' => 'songs', 'title' => 'New Song', 'source_title' => null, 'openlp_search_title' => null, 'song_id' => null, 'metadata' => null],
        ];

        $result = $this->service->merge($churchService, $incomingItems, ChurchServiceItemSource::Email);

        $this->assertTrue($result->wasMerged);
        $this->assertFalse($result->wasStaged);
    }

    #[Test]
    public function test_pending_merge_metadata_contains_proposed_items_and_classification(): void
    {
        $churchService = $this->createLivestreamService([
            ['type' => 'songs', 'title' => 'Song A', 'confidence' => 'high'],
            ['type' => 'custom', 'title' => 'Sermon', 'section_type' => ServiceSectionType::Sermon, 'confidence' => 'high'],
        ]);

        $incomingItems = [
            ['position' => 1, 'type' => 'songs', 'title' => 'Different Song', 'source_title' => null, 'openlp_search_title' => 'different@', 'song_id' => null, 'metadata' => null],
            ['position' => 2, 'type' => 'custom', 'title' => 'Prayer', 'section_type' => ServiceSectionType::Prayer->value, 'source_title' => null, 'openlp_search_title' => null, 'song_id' => null, 'metadata' => null],
        ];

        $result = $this->service->merge($churchService, $incomingItems, ChurchServiceItemSource::OpenLp);

        $this->assertTrue($result->wasStaged);

        $importMetadata = $churchService->fresh()->import_metadata?->toArray() ?? [];
        $pending = $importMetadata['pending_structure_merge'];

        $this->assertArrayNotHasKey('incoming_source', $pending);
        $this->assertArrayHasKey('created_at', $pending);
        $this->assertArrayHasKey('confidence', $pending);
        $this->assertCount(2, $pending['proposed_items']);
        $this->assertArrayHasKey('classification', $pending);
        $this->assertNotEmpty($pending['classification']['review_required']);
    }

    #[Test]
    public function test_a_second_staged_proposal_does_not_discard_the_first(): void
    {
        $churchService = $this->createLivestreamService([
            ['type' => 'songs', 'title' => 'Song A', 'confidence' => 'high'],
            ['type' => 'custom', 'title' => 'Sermon', 'section_type' => ServiceSectionType::Sermon, 'confidence' => 'high'],
        ]);

        $this->service->merge($churchService, [
            ['position' => 1, 'type' => 'songs', 'title' => 'OpenLP Song', 'source_title' => null, 'openlp_search_title' => 'openlp song@', 'song_id' => null, 'metadata' => null],
        ], ChurchServiceItemSource::OpenLp);

        // Importing every source before reviewing is the efficient order, so two
        // proposals can queue against the same recording. Losing the first would
        // lose exactly the three-source comparison the review exists to make.
        $this->service->merge($churchService->fresh(), [
            ['position' => 1, 'type' => 'songs', 'title' => 'Email Song', 'source_title' => null, 'openlp_search_title' => 'email song@', 'song_id' => null, 'metadata' => null],
        ], ChurchServiceItemSource::Email);

        $churchService->refresh();
        $pending = $churchService->import_metadata?->toArray()['pending_structure_merge'] ?? [];

        $this->assertSame('email', $churchService->pending_structure_merge_source);
        $this->assertCount(1, $pending['superseded_proposals']);
        $this->assertSame('openlp', $pending['superseded_proposals'][0]['incoming_source']);
        $this->assertNotEmpty($pending['superseded_proposals'][0]['proposed_items']);
    }

    /**
     * @param  list<array{type: string, title: string, confidence: string, section_type?: ServiceSectionType, song_id?: int|null}>  $items
     */
    private function createLivestreamService(array $items): ChurchService
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-03-23',
            'service' => SermonService::Morning->value,
            'source' => ChurchServiceItemSource::Livestream->value,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        foreach ($items as $index => $item) {
            $sectionType = $item['section_type'] ?? null;

            if ($sectionType === null) {
                $sectionType = match ($item['type']) {
                    'songs' => ServiceSectionType::Song,
                    'bibles' => ServiceSectionType::BibleReading,
                    default => ServiceSectionType::inferFromTitle($item['title']),
                };
            }

            $section = ServiceSection::factory()->create([
                'media_processing_log_id' => $processingLog->id,
                'church_service_item_id' => null,
                'section_type' => $sectionType,
                'section_order' => $index + 1,
                'title' => $item['title'],
                'confidence' => match ($item['confidence']) {
                    'high' => 0.9,
                    'low' => 0.2,
                    default => 0.1,
                },
            ]);

            $serviceItem = ChurchServiceItem::factory()->livestream()->create([
                'church_service_id' => $churchService->id,
                'position' => $index + 1,
                'type' => $item['type'],
                'section_type' => $sectionType,
                'title' => $item['title'],
                'song_id' => $item['song_id'] ?? null,
                'livestream_processing_id' => $processingLog->processing_id,
                'livestream_service_section_id' => $section->id,
                'metadata' => [
                    'livestream_projection' => [
                        'source_segment_ids' => [],
                        'confidence_level' => $item['confidence'],
                    ],
                ],
            ]);

            $section->forceFill(['church_service_item_id' => $serviceItem->id])->save();
        }

        return $churchService;
    }

    #[Test]
    public function test_direct_merge_propagates_items_constraint_violation(): void
    {
        $churchService = ChurchService::factory()->create();

        $mock = $this->mock(ChurchServiceItemSyncService::class);
        $previous = new \PDOException('Duplicate entry for key "church_service_items_active_position_unique"');
        $mock->shouldReceive('sync')
            ->once()
            ->andThrow(new UniqueConstraintViolationException('default', 'INSERT INTO ...', [], $previous));

        $this->app->instance(ChurchServiceItemSyncService::class, $mock);
        $service = app(ChurchServiceStructureMergeService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ordering conflict');

        $service->merge($churchService, [], ChurchServiceItemSource::OpenLp);
    }
}
