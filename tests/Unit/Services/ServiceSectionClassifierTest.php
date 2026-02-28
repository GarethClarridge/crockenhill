<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\SermonService;
use App\Enums\ServiceSectionStatus;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Services\ServiceSectionClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceSectionClassifierTest extends TestCase
{
    use RefreshDatabase;

    private ServiceSectionClassifier $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ServiceSectionClassifier;
    }

    #[Test]
    public function it_skips_when_no_matching_church_service(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-03-01',
            'extracted_service' => SermonService::MORNING->value,
        ]);

        $result = $this->service->classify($processingLog);

        $this->assertTrue($result['skipped']);
        $this->assertSame('no_matching_church_service', $result['skip_reason']);
        $this->assertCount(0, $result['sections']);
    }

    #[Test]
    public function it_aligns_ordered_openlp_items_to_ordered_segments(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-03-08',
            'service' => SermonService::MORNING->value,
        ]);

        $songOne = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Song One',
        ]);

        $prayer = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 2,
            'type' => 'custom',
            'title' => 'Opening Prayer',
        ]);

        $songTwo = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 3,
            'type' => 'songs',
            'title' => 'Song Two',
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-03-08',
            'extracted_service' => SermonService::MORNING->value,
        ]);

        $segmentOne = LivestreamSegment::factory()->song()->create([
            'media_processing_log_id' => $processingLog->id,
            'segment_order' => 1,
            'start_time' => 120.0,
            'end_time' => 300.0,
            'duration' => 180.0,
        ]);

        $segmentTwo = LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $processingLog->id,
            'segment_order' => 2,
            'start_time' => 300.0,
            'end_time' => 540.0,
            'duration' => 240.0,
        ]);

        $segmentThree = LivestreamSegment::factory()->song()->create([
            'media_processing_log_id' => $processingLog->id,
            'segment_order' => 3,
            'start_time' => 540.0,
            'end_time' => 720.0,
            'duration' => 180.0,
        ]);

        $result = $this->service->classify($processingLog);

        $this->assertFalse($result['skipped']);
        $this->assertNull($result['skip_reason']);
        $this->assertCount(3, $result['sections']);

        $this->assertSame($songOne->id, $result['sections'][0]['church_service_item_id']);
        $this->assertSame(ServiceSectionType::SONG->value, $result['sections'][0]['section_type']);
        $this->assertSame(ServiceSectionStatus::IDENTIFIED->value, $result['sections'][0]['status']);
        $this->assertFalse($result['sections'][0]['needs_manual_review']);
        $this->assertSame([$segmentOne->id], $result['sections'][0]['source_segment_ids']);
        $this->assertSame('high', $result['sections'][0]['metadata']['confidence_level']);

        $this->assertSame($prayer->id, $result['sections'][1]['church_service_item_id']);
        $this->assertSame(ServiceSectionType::PRAYER->value, $result['sections'][1]['section_type']);
        $this->assertSame([$segmentTwo->id], $result['sections'][1]['source_segment_ids']);
        $this->assertSame('high', $result['sections'][1]['metadata']['confidence_level']);

        $this->assertSame($songTwo->id, $result['sections'][2]['church_service_item_id']);
        $this->assertSame(ServiceSectionType::SONG->value, $result['sections'][2]['section_type']);
        $this->assertSame([$segmentThree->id], $result['sections'][2]['source_segment_ids']);
        $this->assertSame('high', $result['sections'][2]['metadata']['confidence_level']);
    }

    #[Test]
    public function it_sets_confidence_metadata_and_review_flags_for_ambiguous_or_missing_matches(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-03-15',
            'service' => SermonService::MORNING->value,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Song One',
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 2,
            'type' => 'custom',
            'title' => 'Notices',
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-03-15',
            'extracted_service' => SermonService::MORNING->value,
        ]);

        $speechOnlySegment = LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $processingLog->id,
            'segment_order' => 1,
            'start_time' => 60.0,
            'end_time' => 300.0,
            'duration' => 240.0,
        ]);

        $result = $this->service->classify($processingLog);

        $this->assertCount(2, $result['sections']);

        $firstSection = $result['sections'][0];
        $this->assertSame(ServiceSectionStatus::IDENTIFIED->value, $firstSection['status']);
        $this->assertTrue($firstSection['needs_manual_review']);
        $this->assertSame([$speechOnlySegment->id], $firstSection['source_segment_ids']);
        $this->assertSame('low', $firstSection['metadata']['confidence_level']);
        $this->assertSame('expected_type_mismatch', $firstSection['metadata']['review_reason']);

        $secondSection = $result['sections'][1];
        $this->assertSame(ServiceSectionStatus::SKIPPED->value, $secondSection['status']);
        $this->assertTrue($secondSection['needs_manual_review']);
        $this->assertSame([], $secondSection['source_segment_ids']);
        $this->assertSame('none', $secondSection['metadata']['confidence_level']);
        $this->assertSame('no_segment_available', $secondSection['metadata']['review_reason']);
    }

    #[Test]
    public function it_flags_segment_overlap_or_order_anomalies_for_review(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-03-22',
            'service' => SermonService::MORNING->value,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Song One',
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 2,
            'type' => 'songs',
            'title' => 'Song Two',
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-03-22',
            'extracted_service' => SermonService::MORNING->value,
        ]);

        LivestreamSegment::factory()->song()->create([
            'media_processing_log_id' => $processingLog->id,
            'segment_order' => 1,
            'start_time' => 100.0,
            'end_time' => 300.0,
            'duration' => 200.0,
        ]);

        LivestreamSegment::factory()->song()->create([
            'media_processing_log_id' => $processingLog->id,
            'segment_order' => 2,
            'start_time' => 250.0,
            'end_time' => 450.0,
            'duration' => 200.0,
        ]);

        $result = $this->service->classify($processingLog);

        $this->assertCount(2, $result['sections']);
        $secondSection = $result['sections'][1];

        $this->assertTrue($secondSection['needs_manual_review']);
        $this->assertSame('low', $secondSection['metadata']['confidence_level']);
        $this->assertSame('segment_overlap_or_order_anomaly', $secondSection['metadata']['review_reason']);
        $this->assertContains('segment_overlap_detected', $secondSection['metadata']['anomalies']);
    }
}
