<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\ChurchServiceItemSource;
use App\Enums\InboundEmailStatus;
use App\Enums\SermonService;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\InboundEmail;
use App\Services\ImportChurchServiceFromOpenLp;
use App\Services\InboundEmailImportService;
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
        $churchService = $this->createLivestreamService('2024-11-17', SermonService::MORNING, [
            ['type' => 'songs', 'title' => 'Amazing Grace', 'confidence' => 'high'],
            ['type' => 'custom', 'title' => 'Sermon', 'section_type' => ServiceSectionType::SERMON, 'confidence' => 'high'],
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

        $this->assertArrayHasKey('pending_structure_merge', $importMetadata);
        $this->assertTrue($fresh->needs_review);

        $items = $fresh->items()->orderBy('position')->get();
        $this->assertSame('Amazing Grace', $items[0]->title);
        $this->assertSame('Sermon', $items[1]->title);
    }

    #[Test]
    public function test_openlp_import_auto_merges_when_low_confidence_livestream(): void
    {
        $this->createLivestreamService('2024-11-17', SermonService::MORNING, [
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

        $this->assertArrayNotHasKey('pending_structure_merge', $importMetadata);

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
        $churchService = $this->createLivestreamService('2026-03-22', SermonService::MORNING, [
            ['type' => 'songs', 'title' => 'Amazing Grace', 'confidence' => 'high'],
            ['type' => 'custom', 'title' => 'Sermon', 'section_type' => ServiceSectionType::SERMON, 'confidence' => 'high'],
        ]);

        $inboundEmail = InboundEmail::factory()->create([
            'status' => InboundEmailStatus::PENDING,
        ]);

        $parseResult = new \App\Data\OosEmailParseResult(
            date: '2026-03-22',
            service: SermonService::MORNING,
            items: [
                ['position' => 1, 'type' => 'songs', 'title' => 'Different Song', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
                ['position' => 2, 'type' => 'custom', 'title' => 'Opening Prayer', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => ['section_type' => ServiceSectionType::PRAYER->value]],
            ],
            confidenceScore: 0.9,
            needsReview: false,
            shouldImport: true,
            importMetadata: [],
        );

        $service = app(InboundEmailImportService::class);
        $resultService = $service->import($inboundEmail, $parseResult, reviewedByUserId: 1);

        $fresh = $resultService->fresh();
        $importMetadata = $fresh->import_metadata?->toArray() ?? [];

        $this->assertArrayHasKey('pending_structure_merge', $importMetadata);
        $this->assertTrue($fresh->needs_review);

        $items = $fresh->items()->orderBy('position')->get();
        $this->assertSame('Amazing Grace', $items[0]->title);
        $this->assertSame('Sermon', $items[1]->title);

        $inboundEmail->refresh();
        $this->assertSame(InboundEmailStatus::PROCESSED, $inboundEmail->status);
    }

    #[Test]
    public function test_email_import_auto_merges_when_no_livestream_items(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-03-22',
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

        $inboundEmail = InboundEmail::factory()->create([
            'status' => InboundEmailStatus::PENDING,
        ]);

        $parseResult = new \App\Data\OosEmailParseResult(
            date: '2026-03-22',
            service: SermonService::MORNING,
            items: [
                ['position' => 1, 'type' => 'songs', 'title' => 'New Song', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
            ],
            confidenceScore: 0.9,
            needsReview: false,
            shouldImport: true,
            importMetadata: [],
        );

        $service = app(InboundEmailImportService::class);
        $resultService = $service->import($inboundEmail, $parseResult, reviewedByUserId: 1);

        $fresh = $resultService->fresh();
        $importMetadata = $fresh->import_metadata?->toArray() ?? [];

        $this->assertArrayNotHasKey('pending_structure_merge', $importMetadata);
    }

    /**
     * @param  list<array{type: string, title: string, confidence: string, section_type?: ServiceSectionType}>  $items
     */
    private function createLivestreamService(string $date, SermonService $sermonService, array $items): ChurchService
    {
        $churchService = ChurchService::factory()->create([
            'date' => $date,
            'service' => $sermonService->value,
            'source' => ChurchServiceItemSource::LIVESTREAM->value,
        ]);

        foreach ($items as $index => $item) {
            $sectionType = $item['section_type'] ?? match ($item['type']) {
                'songs' => ServiceSectionType::SONG,
                'bibles' => ServiceSectionType::BIBLE_READING,
                default => ServiceSectionType::inferFromTitle($item['title']),
            };

            ChurchServiceItem::factory()->livestream()->create([
                'church_service_id' => $churchService->id,
                'position' => $index + 1,
                'type' => $item['type'],
                'section_type' => $sectionType,
                'title' => $item['title'],
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
