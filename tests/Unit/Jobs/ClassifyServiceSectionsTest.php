<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Enums\SermonService;
use App\Jobs\ClassifyServiceSections;
use App\Jobs\PrepareSectionPublicationCandidates;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Services\ServiceSectionClassifier;
use App\Services\ServiceSectionSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class ClassifyServiceSectionsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_writes_sections_for_successful_classification(): void
    {
        config(['media-processing.section_classification.enabled' => true]);

        $churchService = ChurchService::factory()->create([
            'date' => '2026-03-22',
            'service' => SermonService::MORNING->value,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Opening Song',
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 2,
            'type' => 'custom',
            'title' => 'Opening Prayer',
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'status' => 'pending',
            'extracted_date' => '2026-03-22',
            'extracted_service' => SermonService::MORNING->value,
        ]);

        LivestreamSegment::factory()->song()->create([
            'media_processing_log_id' => $processingLog->id,
            'segment_order' => 1,
        ]);

        LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $processingLog->id,
            'segment_order' => 2,
        ]);

        $job = new ClassifyServiceSections($processingLog);
        $job->handle(new ServiceSectionClassifier, new ServiceSectionSyncService);

        $processingLog->refresh();

        $this->assertSame('processing', $processingLog->status->value);
        $this->assertSame('section_classification_complete', $processingLog->current_step);
        $this->assertDatabaseCount('service_sections', 2);
        $this->assertDatabaseHas('service_sections', [
            'media_processing_log_id' => $processingLog->id,
            'section_order' => 1,
            'needs_manual_review' => true,
            'church_service_item_id' => null,
        ]);
    }

    #[Test]
    public function it_completes_classification_with_audio_only_sections_when_no_matching_church_service(): void
    {
        config(['media-processing.section_classification.enabled' => true]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'status' => 'pending',
            'extracted_date' => '2026-03-29',
            'extracted_service' => SermonService::MORNING->value,
        ]);

        LivestreamSegment::factory()->song()->create([
            'media_processing_log_id' => $processingLog->id,
            'segment_order' => 1,
        ]);

        $job = new ClassifyServiceSections($processingLog);
        $job->handle(new ServiceSectionClassifier, new ServiceSectionSyncService);

        $processingLog->refresh();

        $this->assertSame('processing', $processingLog->status->value);
        $this->assertSame('section_classification_complete', $processingLog->current_step);
        $this->assertDatabaseCount('service_sections', 1);
        $this->assertDatabaseHas('service_sections', [
            'media_processing_log_id' => $processingLog->id,
            'section_order' => 1,
            'section_type' => 'song',
            'church_service_item_id' => null,
        ]);
    }

    #[Test]
    public function it_marks_processing_failed_when_classifier_throws_unrecoverable_error(): void
    {
        config(['media-processing.section_classification.enabled' => true]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'status' => 'pending',
        ]);

        $classifier = Mockery::mock(ServiceSectionClassifier::class);
        $classifier->shouldReceive('classify')
            ->once()
            ->andThrow(new RuntimeException('Classifier exploded'));

        $job = new ClassifyServiceSections($processingLog);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Classifier exploded');

        try {
            $job->handle($classifier, new ServiceSectionSyncService);
        } finally {
            $processingLog->refresh();

            $this->assertSame('failed', $processingLog->status->value);
            $this->assertStringContainsString(
                'Service section classification failed: Classifier exploded',
                $processingLog->error_message ?? ''
            );
        }
    }

    #[Test]
    public function it_preserves_run_status_when_reclassifying_existing_completed_run(): void
    {
        config(['media-processing.section_classification.enabled' => true]);
        Queue::fake();

        $churchService = ChurchService::factory()->create([
            'date' => '2026-03-30',
            'service' => SermonService::MORNING->value,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Opening Song',
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'status' => 'completed',
            'current_step' => 'completed',
            'extracted_date' => '2026-03-30',
            'extracted_service' => SermonService::MORNING->value,
        ]);

        LivestreamSegment::factory()->song()->create([
            'media_processing_log_id' => $processingLog->id,
            'segment_order' => 1,
        ]);

        $job = new ClassifyServiceSections($processingLog, preserveRunStatus: true);
        $job->handle(new ServiceSectionClassifier, new ServiceSectionSyncService);

        $processingLog->refresh();

        $this->assertSame('completed', $processingLog->status->value);
        $this->assertSame('completed', $processingLog->current_step);
        $this->assertDatabaseCount('service_sections', 1);
        Queue::assertPushed(PrepareSectionPublicationCandidates::class);
    }
}
