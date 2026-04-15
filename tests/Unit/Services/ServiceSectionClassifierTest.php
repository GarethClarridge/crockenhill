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
    public function it_classifies_audio_only_segments_when_no_matching_church_service(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-03-01',
            'extracted_service' => SermonService::Morning->value,
        ]);

        $introSpeech = LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $processingLog->id,
            'segment_index' => 1,
            'segment_order' => 1,
            'start_time' => 0.0,
            'end_time' => 480.0,
            'duration' => 480.0,
        ]);

        $song = LivestreamSegment::factory()->song()->create([
            'media_processing_log_id' => $processingLog->id,
            'segment_index' => 2,
            'segment_order' => 2,
            'start_time' => 480.0,
            'end_time' => 720.0,
            'duration' => 240.0,
        ]);

        $sermon = LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $processingLog->id,
            'segment_index' => 3,
            'segment_order' => 3,
            'start_time' => 720.0,
            'end_time' => 2520.0,
            'duration' => 1800.0,
        ]);

        $result = $this->service->classify($processingLog);

        $this->assertFalse($result['skipped']);
        $this->assertNull($result['skip_reason']);
        $this->assertCount(3, $result['sections']);

        $this->assertNull($result['sections'][0]['church_service_item_id']);
        $this->assertSame(ServiceSectionType::OTHER->value, $result['sections'][0]['section_type']);
        $this->assertSame([$introSpeech->id], $result['sections'][0]['source_segment_ids']);
        $this->assertTrue($result['sections'][0]['needs_manual_review']);
        $this->assertSame('low', $result['sections'][0]['metadata']['confidence_level']);
        $this->assertSame('audio_only', $result['sections'][0]['metadata']['classification_mode']);
        $this->assertSame('audio_only_speech_segment', $result['sections'][0]['metadata']['review_reason']);

        $this->assertNull($result['sections'][1]['church_service_item_id']);
        $this->assertSame(ServiceSectionType::SONG->value, $result['sections'][1]['section_type']);
        $this->assertSame([$song->id], $result['sections'][1]['source_segment_ids']);
        $this->assertTrue($result['sections'][1]['needs_manual_review']);
        $this->assertSame('low', $result['sections'][1]['metadata']['confidence_level']);
        $this->assertSame('audio_only', $result['sections'][1]['metadata']['classification_mode']);

        $this->assertNull($result['sections'][2]['church_service_item_id']);
        $this->assertSame(ServiceSectionType::SERMON->value, $result['sections'][2]['section_type']);
        $this->assertSame([$sermon->id], $result['sections'][2]['source_segment_ids']);
        $this->assertFalse($result['sections'][2]['needs_manual_review']);
        $this->assertSame('high', $result['sections'][2]['metadata']['confidence_level']);
        $this->assertSame('audio_only', $result['sections'][2]['metadata']['classification_mode']);
        $this->assertSame('single_dominant_speech_block', $result['sections'][2]['metadata']['sermon_detection_strategy']);
    }

    #[Test]
    public function it_links_the_matching_church_service_but_keeps_audio_first_sections(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-03-08',
            'service' => SermonService::Morning->value,
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
            'extracted_service' => SermonService::Morning->value,
        ]);

        $segmentOne = LivestreamSegment::factory()->song()->create([
            'media_processing_log_id' => $processingLog->id,
            'segment_index' => 1,
            'segment_order' => 1,
            'start_time' => 120.0,
            'end_time' => 300.0,
            'duration' => 180.0,
        ]);

        $segmentTwo = LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $processingLog->id,
            'segment_index' => 2,
            'segment_order' => 2,
            'start_time' => 300.0,
            'end_time' => 540.0,
            'duration' => 240.0,
        ]);

        $segmentThree = LivestreamSegment::factory()->song()->create([
            'media_processing_log_id' => $processingLog->id,
            'segment_index' => 3,
            'segment_order' => 3,
            'start_time' => 540.0,
            'end_time' => 720.0,
            'duration' => 180.0,
        ]);

        $result = $this->service->classify($processingLog);

        $this->assertFalse($result['skipped']);
        $this->assertNull($result['skip_reason']);
        $this->assertSame($churchService->id, $processingLog->fresh()->church_service_id);
        $this->assertCount(3, $result['sections']);

        $this->assertNull($result['sections'][0]['church_service_item_id']);
        $this->assertSame(ServiceSectionType::SONG->value, $result['sections'][0]['section_type']);
        $this->assertSame(ServiceSectionStatus::Identified->value, $result['sections'][0]['status']);
        $this->assertTrue($result['sections'][0]['needs_manual_review']);
        $this->assertSame([$segmentOne->id], $result['sections'][0]['source_segment_ids']);
        $this->assertSame('low', $result['sections'][0]['metadata']['confidence_level']);

        $this->assertNull($result['sections'][1]['church_service_item_id']);
        $this->assertSame(ServiceSectionType::OTHER->value, $result['sections'][1]['section_type']);
        $this->assertSame([$segmentTwo->id], $result['sections'][1]['source_segment_ids']);
        $this->assertSame('low', $result['sections'][1]['metadata']['confidence_level']);

        $this->assertNull($result['sections'][2]['church_service_item_id']);
        $this->assertSame(ServiceSectionType::SONG->value, $result['sections'][2]['section_type']);
        $this->assertSame([$segmentThree->id], $result['sections'][2]['source_segment_ids']);
        $this->assertSame('low', $result['sections'][2]['metadata']['confidence_level']);
    }

    #[Test]
    public function it_does_not_use_oos_section_type_hints_during_audio_only_classification(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-03-10',
            'service' => SermonService::Morning->value,
        ]);

        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'custom',
            'title' => 'Ministry Moment',
            'metadata' => ['section_type' => ServiceSectionType::WELCOME->value],
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-03-10',
            'extracted_service' => SermonService::Morning->value,
        ]);

        $segment = LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $processingLog->id,
            'segment_order' => 1,
            'start_time' => 0.0,
            'end_time' => 180.0,
            'duration' => 180.0,
        ]);

        $result = $this->service->classify($processingLog);

        $this->assertCount(1, $result['sections']);
        $this->assertNull($result['sections'][0]['church_service_item_id']);
        $this->assertSame(ServiceSectionType::OTHER->value, $result['sections'][0]['section_type']);
        $this->assertSame([$segment->id], $result['sections'][0]['source_segment_ids']);
        $this->assertSame($churchService->id, $processingLog->fresh()->church_service_id);
    }

    #[Test]
    public function it_keeps_audio_only_sections_even_when_a_matching_service_has_more_items_than_segments(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-03-15',
            'service' => SermonService::Morning->value,
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
            'extracted_service' => SermonService::Morning->value,
        ]);

        $speechOnlySegment = LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $processingLog->id,
            'segment_order' => 1,
            'start_time' => 60.0,
            'end_time' => 300.0,
            'duration' => 240.0,
        ]);

        $result = $this->service->classify($processingLog);

        $this->assertCount(1, $result['sections']);

        $firstSection = $result['sections'][0];
        $this->assertSame(ServiceSectionStatus::Identified->value, $firstSection['status']);
        $this->assertTrue($firstSection['needs_manual_review']);
        $this->assertSame([$speechOnlySegment->id], $firstSection['source_segment_ids']);
        $this->assertSame('low', $firstSection['metadata']['confidence_level']);
        $this->assertSame('no_high_confidence_sermon_candidate', $firstSection['metadata']['review_reason']);
    }

    #[Test]
    public function it_keeps_overlapping_song_segments_as_audio_only_song_sections(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-03-22',
            'service' => SermonService::Morning->value,
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
            'extracted_service' => SermonService::Morning->value,
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
        $this->assertSame('audio_only_song_segment', $secondSection['metadata']['review_reason']);
    }

    #[Test]
    public function it_keeps_out_of_order_song_segments_as_audio_only_song_sections(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-03-29',
            'service' => SermonService::Morning->value,
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
            'extracted_date' => '2026-03-29',
            'extracted_service' => SermonService::Morning->value,
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
            'segment_order' => 1,
            'start_time' => 320.0,
            'end_time' => 520.0,
            'duration' => 200.0,
        ]);

        $result = $this->service->classify($processingLog);

        $this->assertCount(2, $result['sections']);
        $secondSection = $result['sections'][1];

        $this->assertTrue($secondSection['needs_manual_review']);
        $this->assertSame('low', $secondSection['metadata']['confidence_level']);
        $this->assertSame('audio_only_song_segment', $secondSection['metadata']['review_reason']);
    }

    #[Test]
    public function it_classifies_all_speech_as_other_when_no_segment_meets_sermon_threshold(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-04-05',
            'extracted_service' => SermonService::Morning->value,
        ]);

        LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $processingLog->id,
            'segment_order' => 1,
            'start_time' => 0.0,
            'end_time' => 600.0,
            'duration' => 600.0,
        ]);

        LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $processingLog->id,
            'segment_order' => 2,
            'start_time' => 600.0,
            'end_time' => 1100.0,
            'duration' => 500.0,
        ]);

        $result = $this->service->classify($processingLog);

        $this->assertFalse($result['skipped']);
        $this->assertCount(2, $result['sections']);

        foreach ($result['sections'] as $section) {
            $this->assertSame(ServiceSectionType::OTHER->value, $section['section_type']);
            $this->assertTrue($section['needs_manual_review']);
            $this->assertSame('low', $section['metadata']['confidence_level']);
            $this->assertSame('no_high_confidence_sermon_candidate', $section['metadata']['review_reason']);
        }
    }

    #[Test]
    public function it_classifies_all_speech_as_other_when_two_segments_exceed_sermon_threshold(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-04-12',
            'extracted_service' => SermonService::Morning->value,
        ]);

        LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $processingLog->id,
            'segment_order' => 1,
            'start_time' => 0.0,
            'end_time' => 1500.0,
            'duration' => 1500.0,
        ]);

        LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $processingLog->id,
            'segment_order' => 2,
            'start_time' => 1500.0,
            'end_time' => 2800.0,
            'duration' => 1300.0,
        ]);

        $result = $this->service->classify($processingLog);

        $this->assertFalse($result['skipped']);
        $this->assertCount(2, $result['sections']);

        foreach ($result['sections'] as $section) {
            $this->assertSame(ServiceSectionType::OTHER->value, $section['section_type']);
            $this->assertTrue($section['needs_manual_review']);
            $this->assertSame('low', $section['metadata']['confidence_level']);
            $this->assertSame('no_high_confidence_sermon_candidate', $section['metadata']['review_reason']);
        }
    }

    #[Test]
    public function it_classifies_all_speech_as_other_when_candidate_fails_ratio_check(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-04-19',
            'extracted_service' => SermonService::Morning->value,
        ]);

        // 22 minutes - only one exceeds 20 min threshold
        LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $processingLog->id,
            'segment_order' => 1,
            'start_time' => 0.0,
            'end_time' => 1320.0,
            'duration' => 1320.0,
        ]);

        // 15 minutes - below threshold but 1320 < 900 * 1.5 = 1350, so ratio fails
        LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $processingLog->id,
            'segment_order' => 2,
            'start_time' => 1320.0,
            'end_time' => 2220.0,
            'duration' => 900.0,
        ]);

        $result = $this->service->classify($processingLog);

        $this->assertFalse($result['skipped']);
        $this->assertCount(2, $result['sections']);

        foreach ($result['sections'] as $section) {
            $this->assertSame(ServiceSectionType::OTHER->value, $section['section_type']);
            $this->assertTrue($section['needs_manual_review']);
            $this->assertSame('low', $section['metadata']['confidence_level']);
            $this->assertSame('no_high_confidence_sermon_candidate', $section['metadata']['review_reason']);
        }
    }
}
