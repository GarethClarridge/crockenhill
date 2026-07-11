<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Actions\ServiceReview\ResolvePendingStructureMerge;
use App\Data\OosEmailParseResult;
use App\Enums\ChurchServiceItemSource;
use App\Enums\InboundEmailStatus;
use App\Enums\SermonService;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\InboundEmail;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\User;
use App\Services\ChurchService\ImportChurchServiceFromOpenLp;
use App\Services\ChurchService\LivestreamChurchServiceProjectionService;
use App\Services\Email\InboundEmailImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OpenLpArchiveFactory;
use Tests\TestCase;

class StructureMergeIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();
        Queue::fake();
    }

    #[Test]
    public function test_openlp_import_stages_review_when_high_confidence_livestream_disagrees(): void
    {
        $churchService = $this->createLivestreamService('2024-11-17', SermonService::Morning, [
            ['type' => 'songs', 'title' => 'Amazing Grace', 'confidence' => 'high'],
            ['type' => 'custom', 'title' => 'Sermon', 'section_type' => ServiceSectionType::Sermon, 'confidence' => 'high'],
        ]);

        $upload = OpenLpArchiveFactory::makeUpload(
            archiveName: '2024-11-17 AM.osz',
            osjName: '2024-11-17 AM.osj',
            payload: OpenLpArchiveFactory::payload([
                OpenLpArchiveFactory::serviceItem(
                    OpenLpArchiveFactory::songHeader('How Great Thou Art', 'how great thou art@')
                ),
                OpenLpArchiveFactory::serviceItem(
                    OpenLpArchiveFactory::customHeader('Opening Prayer')
                ),
            ]),
        );

        $service = app(ImportChurchServiceFromOpenLp::class);
        $result = $service->import($upload);

        $this->assertFalse($result->wasCreated);
        $this->assertSame($churchService->id, $result->churchService->id);

        $fresh = $result->churchService->fresh();
        $importMetadata = $fresh->import_metadata?->toArray() ?? [];

        $this->assertNotNull($fresh->pending_structure_merge_source);
        $this->assertTrue($fresh->needs_review);

        $items = $fresh->items()->orderBy('position')->get();
        $this->assertSame('Amazing Grace', $items[0]->title);
        $this->assertSame('Sermon', $items[1]->title);
    }

    #[Test]
    public function test_openlp_import_auto_merges_when_low_confidence_livestream(): void
    {
        $this->createLivestreamService('2024-11-17', SermonService::Morning, [
            ['type' => 'songs', 'title' => 'Unknown Song', 'confidence' => 'low'],
        ]);

        $upload = OpenLpArchiveFactory::makeUpload(
            archiveName: '2024-11-17 AM.osz',
            osjName: '2024-11-17 AM.osj',
            payload: OpenLpArchiveFactory::payload([
                OpenLpArchiveFactory::serviceItem(
                    OpenLpArchiveFactory::songHeader('Amazing Grace', 'amazing grace@')
                ),
            ]),
        );

        $service = app(ImportChurchServiceFromOpenLp::class);
        $result = $service->import($upload);

        $fresh = $result->churchService->fresh();
        $importMetadata = $fresh->import_metadata?->toArray() ?? [];

        $this->assertNull($fresh->pending_structure_merge_source);

        $items = $fresh->items()->orderBy('position')->get();
        $songTitles = $items->pluck('title')->toArray();
        $this->assertContains('Amazing Grace', $songTitles, 'The OpenLP song should be present after auto-merge');
    }

    #[Test]
    public function test_openlp_import_into_new_service_works_normally(): void
    {
        $upload = OpenLpArchiveFactory::makeUpload(
            archiveName: '2024-11-17 AM.osz',
            osjName: '2024-11-17 AM.osj',
            payload: OpenLpArchiveFactory::payload([
                OpenLpArchiveFactory::serviceItem(
                    OpenLpArchiveFactory::songHeader('Amazing Grace', 'amazing grace@')
                ),
                OpenLpArchiveFactory::serviceItem(
                    OpenLpArchiveFactory::customHeader('Notices')
                ),
            ]),
        );

        $service = app(ImportChurchServiceFromOpenLp::class);
        $result = $service->import($upload);

        $this->assertTrue($result->wasCreated);
        $this->assertCount(2, $result->churchService->items);
    }

    #[Test]
    public function test_email_import_stages_review_when_high_confidence_livestream_disagrees(): void
    {
        $churchService = $this->createLivestreamService('2026-03-22', SermonService::Morning, [
            ['type' => 'songs', 'title' => 'Amazing Grace', 'confidence' => 'high'],
            ['type' => 'custom', 'title' => 'Sermon', 'section_type' => ServiceSectionType::Sermon, 'confidence' => 'high'],
        ]);

        $inboundEmail = InboundEmail::factory()->create([
            'status' => InboundEmailStatus::Pending,
        ]);

        $parseResult = new OosEmailParseResult(
            date: '2026-03-22',
            service: SermonService::Morning,
            items: [
                ['position' => 1, 'type' => 'songs', 'title' => 'Different Song', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
                ['position' => 2, 'type' => 'custom', 'title' => 'Opening Prayer', 'section_type' => ServiceSectionType::Prayer->value, 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
            ],
            confidenceScore: 0.9,
            needsReview: false,
            shouldImport: true,
            importMetadata: [],
        );

        $service = app(InboundEmailImportService::class);
        $importResult = $service->import($inboundEmail, $parseResult, reviewedByUserId: 1);

        $fresh = $importResult->firstResolvedService()?->fresh();
        $this->assertNotNull($fresh);
        $importMetadata = $fresh->import_metadata?->toArray() ?? [];

        $this->assertNotNull($fresh->pending_structure_merge_source);
        $this->assertTrue($fresh->needs_review);

        $items = $fresh->items()->orderBy('position')->get();
        $this->assertSame('Amazing Grace', $items[0]->title);
        $this->assertSame('Sermon', $items[1]->title);

        $inboundEmail->refresh();
        $this->assertSame(InboundEmailStatus::Processed, $inboundEmail->status);
    }

    #[Test]
    public function test_email_import_auto_merges_when_no_livestream_items(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-03-22',
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

        $inboundEmail = InboundEmail::factory()->create([
            'status' => InboundEmailStatus::Pending,
        ]);

        $parseResult = new OosEmailParseResult(
            date: '2026-03-22',
            service: SermonService::Morning,
            items: [
                ['position' => 1, 'type' => 'songs', 'title' => 'New Song', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
            ],
            confidenceScore: 0.9,
            needsReview: false,
            shouldImport: true,
            importMetadata: [],
        );

        $service = app(InboundEmailImportService::class);
        $importResult = $service->import($inboundEmail, $parseResult, reviewedByUserId: 1);

        $fresh = $importResult->firstResolvedService()?->fresh();
        $this->assertNotNull($fresh);
        $importMetadata = $fresh->import_metadata?->toArray() ?? [];

        $this->assertNull($fresh->pending_structure_merge_source);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Full lifecycle tests (projection → import → resolve)
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function test_full_lifecycle_projection_then_openlp_stages_then_accept(): void
    {
        // 1. Project a livestream service
        $log = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-03-27',
            'extracted_service' => SermonService::Morning->value,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song,
            'section_order' => 1,
            'title' => 'Amazing Grace',
            'confidence' => 0.95,
            'needs_manual_review' => false,
        ]);

        $projectionService = app(LivestreamChurchServiceProjectionService::class);
        $projectionResult = $projectionService->project($log);

        $this->assertTrue($projectionResult['projected']);
        $churchService = ChurchService::query()->findOrFail($projectionResult['church_service_id']);
        $this->assertSame(ChurchServiceItemSource::Livestream->value, $churchService->source);

        // 2. OpenLP import arrives — disagrees with high-confidence projected item
        $upload = OpenLpArchiveFactory::makeUpload(
            archiveName: '2026-03-27 AM.osz',
            osjName: '2026-03-27 AM.osj',
            payload: OpenLpArchiveFactory::payload([
                OpenLpArchiveFactory::serviceItem(
                    OpenLpArchiveFactory::songHeader('How Great Thou Art', 'how great thou art@')
                ),
            ]),
        );

        $importResult = app(ImportChurchServiceFromOpenLp::class)->import($upload);

        // Service source must remain 'livestream' while merge is staged
        $churchService->refresh();
        $this->assertSame(ChurchServiceItemSource::Livestream->value, $churchService->source, 'Source must not change to openlp while merge is staged');
        $this->assertTrue($churchService->needs_review);
        $this->assertNotNull($churchService->pending_structure_merge_source);

        // Items unchanged
        $items = $churchService->items()->orderBy('position')->get();
        $this->assertSame('Amazing Grace', $items->first()->title);

        // 3. Admin accepts the incoming merge
        $admin = User::factory()->create(['is_admin' => true]);
        $churchService->refresh(); // reload to pick up staged merge metadata from the import
        $resolution = app(ResolvePendingStructureMerge::class)->execute($churchService, 'accept_incoming', $admin->id);

        $this->assertTrue($resolution->applied);

        $fresh = $resolution->churchService;
        $this->assertSame(ChurchServiceItemSource::OpenLp->value, $fresh->source, 'Source must be openlp after accepting incoming');
        $this->assertFalse($fresh->needs_review);
        $this->assertNull($fresh->pending_structure_merge_source);

        $finalItems = $fresh->items()->orderBy('position')->get();
        $this->assertSame('How Great Thou Art', $finalItems->first()->title);
    }

    #[Test]
    public function test_full_lifecycle_projection_then_openlp_stages_then_keep_current(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-03-27',
            'extracted_service' => SermonService::Morning->value,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song,
            'section_order' => 1,
            'title' => 'Amazing Grace',
            'confidence' => 0.9,
            'needs_manual_review' => false,
        ]);

        $projectionService = app(LivestreamChurchServiceProjectionService::class);
        $projectionResult = $projectionService->project($log);
        $churchService = ChurchService::query()->findOrFail($projectionResult['church_service_id']);

        $upload = OpenLpArchiveFactory::makeUpload(
            archiveName: '2026-03-27 AM.osz',
            osjName: '2026-03-27 AM.osj',
            payload: OpenLpArchiveFactory::payload([
                OpenLpArchiveFactory::serviceItem(
                    OpenLpArchiveFactory::songHeader('Different Song', 'different song@')
                ),
            ]),
        );

        app(ImportChurchServiceFromOpenLp::class)->import($upload);

        $admin = User::factory()->create(['is_admin' => true]);
        $churchService->refresh(); // reload to pick up staged merge metadata from the import
        $resolution = app(ResolvePendingStructureMerge::class)->execute($churchService, 'keep_current', $admin->id);

        $this->assertTrue($resolution->applied);

        $fresh = $resolution->churchService;
        // Source stays livestream when incoming items are rejected
        $this->assertSame(ChurchServiceItemSource::Livestream->value, $fresh->source, 'Source must remain livestream when keeping current items');
        $this->assertFalse($fresh->needs_review);

        $finalItems = $fresh->items()->orderBy('position')->get();
        $this->assertSame('Amazing Grace', $finalItems->first()->title);
    }

    #[Test]
    public function test_openlp_import_keeps_livestream_source_while_merge_is_staged(): void
    {
        // Regression for fix #7: source must not jump to 'openlp' before merge is accepted
        $churchService = $this->createLivestreamService('2024-11-17', SermonService::Morning, [
            ['type' => 'songs', 'title' => 'Amazing Grace', 'confidence' => 'high'],
        ]);

        $upload = OpenLpArchiveFactory::makeUpload(
            archiveName: '2024-11-17 AM.osz',
            osjName: '2024-11-17 AM.osj',
            payload: OpenLpArchiveFactory::payload([
                OpenLpArchiveFactory::serviceItem(
                    OpenLpArchiveFactory::songHeader('How Great Thou Art', 'how great thou art@')
                ),
            ]),
        );

        $result = app(ImportChurchServiceFromOpenLp::class)->import($upload);

        $fresh = $result->churchService->fresh();
        $this->assertSame(
            ChurchServiceItemSource::Livestream->value,
            $fresh->source,
            'Service source must remain livestream when a merge is staged, not yet accepted'
        );
    }

    #[Test]
    public function test_accept_incoming_preserves_needs_review_when_finalize_reopens_it(): void
    {
        // Regression for fix #8: if finalize sets needs_review=true (e.g. prior manual
        // review exists), clearPendingMerge must not overwrite it back to false.
        $previousReviewer = User::factory()->create(['is_admin' => true]);
        $service = ChurchService::factory()->create([
            'date' => '2026-03-27',
            'service' => SermonService::Morning->value,
            'source' => ChurchServiceItemSource::Livestream->value,
            'needs_review' => true,
            'pending_structure_merge_source' => ChurchServiceItemSource::OpenLp->value,
            'import_metadata' => [
                // Simulate a previously-reviewed service so finalize will reopen review
                'manual_review' => [
                    'reviewed_at' => now()->subDay()->toIso8601String(),
                    'reviewed_by_user_id' => $previousReviewer->id,
                ],
                'pending_structure_merge' => [
                    'created_at' => now()->toIso8601String(),
                    'confidence' => ['current' => 'high', 'incoming' => 'high'],
                    'conflicts' => [
                        ['type' => 'title_conflict', 'incoming_item' => ['position' => 1, 'type' => 'songs', 'title' => 'New Song']],
                    ],
                    'proposed_items' => [
                        ['position' => 1, 'type' => 'songs', 'title' => 'New Song', 'section_type' => ServiceSectionType::Song->value, 'source_title' => null, 'openlp_search_title' => 'new song@', 'song_id' => null, 'metadata' => null],
                    ],
                    'classification' => ['auto_merge' => [], 'review_required' => [0], 'unmatched_incoming' => []],
                ],
            ],
        ]);

        ChurchServiceItem::factory()->livestream()->create([
            'church_service_id' => $service->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Old Song',
            'section_type' => ServiceSectionType::Song,
            'metadata' => ['livestream_projection' => ['source_segment_ids' => [], 'confidence_level' => 'high']],
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        $resolution = app(ResolvePendingStructureMerge::class)->execute($service, 'accept_incoming', $admin->id);

        $this->assertTrue($resolution->applied);
        // finalize detects changes on a previously-reviewed service → reopens review
        $this->assertTrue($resolution->churchService->needs_review, 'needs_review must stay true when finalize reopened the review after accepting incoming items');
    }

    /**
     * @param  list<array{type: string, title: string, confidence: string, section_type?: ServiceSectionType}>  $items
     */
    private function createLivestreamService(string $date, SermonService $sermonService, array $items): ChurchService
    {
        $churchService = ChurchService::factory()->create([
            'date' => $date,
            'service' => $sermonService->value,
            'source' => ChurchServiceItemSource::Livestream->value,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        foreach ($items as $index => $item) {
            $sectionType = $item['section_type'] ?? match ($item['type']) {
                'songs' => ServiceSectionType::Song,
                'bibles' => ServiceSectionType::BibleReading,
                default => ServiceSectionType::inferFromTitle($item['title']),
            };

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
}
