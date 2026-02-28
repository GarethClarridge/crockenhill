<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\ServiceSectionStatus;
use App\Enums\ServiceSectionType;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ServiceSectionSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceSectionSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private ServiceSectionSyncService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ServiceSectionSyncService;
    }

    #[Test]
    public function it_creates_service_sections_for_first_sync(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();

        $itemOne = ChurchServiceItem::factory()->create();
        $itemTwo = ChurchServiceItem::factory()->create();

        $this->service->sync($processingLog, [
            $this->sectionData(
                churchServiceItemId: $itemOne->id,
                sectionOrder: 1,
                sectionType: ServiceSectionType::SONG->value,
                title: 'Song One'
            ),
            $this->sectionData(
                churchServiceItemId: $itemTwo->id,
                sectionOrder: 2,
                sectionType: ServiceSectionType::PRAYER->value,
                title: 'Opening Prayer'
            ),
        ]);

        $this->assertDatabaseCount('service_sections', 2);
        $this->assertDatabaseHas('service_sections', [
            'media_processing_log_id' => $processingLog->id,
            'section_order' => 1,
            'section_type' => ServiceSectionType::SONG->value,
            'title' => 'Song One',
        ]);
        $this->assertDatabaseHas('service_sections', [
            'media_processing_log_id' => $processingLog->id,
            'section_order' => 2,
            'section_type' => ServiceSectionType::PRAYER->value,
            'title' => 'Opening Prayer',
        ]);
    }

    #[Test]
    public function it_updates_existing_rows_and_replaces_removed_rows_idempotently(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();

        $itemOne = ChurchServiceItem::factory()->create();
        $itemTwo = ChurchServiceItem::factory()->create();
        $itemThree = ChurchServiceItem::factory()->create();

        $this->service->sync($processingLog, [
            $this->sectionData(
                churchServiceItemId: $itemOne->id,
                sectionOrder: 1,
                sectionType: ServiceSectionType::SONG->value,
                title: 'Song One'
            ),
            $this->sectionData(
                churchServiceItemId: $itemTwo->id,
                sectionOrder: 2,
                sectionType: ServiceSectionType::NOTICES->value,
                title: 'Notices'
            ),
        ]);

        $originalOrderOne = ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->where('section_order', 1)
            ->firstOrFail();

        $originalOrderTwo = ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->where('section_order', 2)
            ->firstOrFail();

        $this->service->sync($processingLog, [
            $this->sectionData(
                churchServiceItemId: $itemOne->id,
                sectionOrder: 1,
                sectionType: ServiceSectionType::SONG->value,
                title: 'Song One (Updated)',
                startTime: 120.0,
                endTime: 330.0,
                duration: 210.0
            ),
            $this->sectionData(
                churchServiceItemId: $itemThree->id,
                sectionOrder: 3,
                sectionType: ServiceSectionType::PRAYER->value,
                title: 'Closing Prayer'
            ),
        ]);

        $updatedOrderOne = ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->where('section_order', 1)
            ->firstOrFail();

        $newOrderThree = ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->where('section_order', 3)
            ->firstOrFail();

        $this->assertSame($originalOrderOne->id, $updatedOrderOne->id);
        $this->assertSame('Song One (Updated)', $updatedOrderOne->title);
        $this->assertSame(120.0, $updatedOrderOne->start_time);
        $this->assertSame(330.0, $updatedOrderOne->end_time);

        $this->assertDatabaseMissing('service_sections', ['id' => $originalOrderTwo->id]);
        $this->assertSame('Closing Prayer', $newOrderThree->title);
        $this->assertDatabaseCount('service_sections', 2);
    }

    /**
     * @return array{
     *     church_service_item_id: int,
     *     section_type: string,
     *     section_order: int,
     *     title: ?string,
     *     start_time: float,
     *     end_time: float,
     *     duration: float,
     *     status: string,
     *     needs_manual_review: bool,
     *     source_segment_ids: array<int, int>,
     *     metadata: array<string, mixed>
     * }
     */
    private function sectionData(
        int $churchServiceItemId,
        int $sectionOrder,
        string $sectionType,
        ?string $title = null,
        float $startTime = 60.0,
        float $endTime = 180.0,
        float $duration = 120.0,
        bool $needsManualReview = false
    ): array {
        return [
            'church_service_item_id' => $churchServiceItemId,
            'section_type' => $sectionType,
            'section_order' => $sectionOrder,
            'title' => $title,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'duration' => $duration,
            'status' => ServiceSectionStatus::IDENTIFIED->value,
            'needs_manual_review' => $needsManualReview,
            'source_segment_ids' => [1],
            'metadata' => [
                'confidence_level' => 'high',
                'classification_mode' => 'openlp_aligned',
            ],
        ];
    }
}
