<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SermonService;
use App\Enums\ServiceSectionType;
use App\Jobs\AlignWithOos;
use App\Jobs\ClassifyServiceSections;
use App\Jobs\ReconcileServiceSections;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\MediaProcessingIdentityResolver;
use App\Services\OosAlignmentService;
use App\Services\ServiceSectionClassifier;
use App\Services\ServiceSectionSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchServicePipelineAlignmentTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function pipeline_without_oos_keeps_audio_only_sections_usable(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create([
            'extracted_date' => '2026-08-02',
            'extracted_service' => SermonService::MORNING->value,
        ]);

        LivestreamSegment::factory()->song()->create([
            'media_processing_log_id' => $processingLog->id,
            'segment_order' => 1,
            'start_time' => 0,
            'end_time' => 180,
            'duration' => 180,
        ]);

        LivestreamSegment::factory()->sermon()->create([
            'media_processing_log_id' => $processingLog->id,
            'segment_order' => 2,
            'start_time' => 180,
            'end_time' => 2280,
            'duration' => 2100,
        ]);

        $classifyJob = new ClassifyServiceSections($processingLog);
        $classifyJob->handle(new ServiceSectionClassifier, new ServiceSectionSyncService);

        $alignJob = new AlignWithOos($processingLog);
        $alignJob->handle(app(OosAlignmentService::class));

        $processingLog->refresh();
        $sections = ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->orderBy('section_order')
            ->get();

        $this->assertNull($processingLog->church_service_id);
        $this->assertCount(2, $sections);
        $this->assertSame(ServiceSectionType::SONG, $sections[0]->section_type);
        $this->assertSame(ServiceSectionType::SERMON, $sections[1]->section_type);
    }

    #[Test]
    public function pipeline_with_oos_enriches_detected_song_sections_after_audio_first_classification(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-08-09',
            'service' => SermonService::MORNING->value,
        ]);

        $song = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Opening Song',
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create([
            'extracted_date' => '2026-08-09',
            'extracted_service' => SermonService::MORNING->value,
        ]);

        LivestreamSegment::factory()->song()->create([
            'media_processing_log_id' => $processingLog->id,
            'segment_order' => 1,
            'start_time' => 0,
            'end_time' => 180,
            'duration' => 180,
        ]);

        $classifyJob = new ClassifyServiceSections($processingLog);
        $classifyJob->handle(new ServiceSectionClassifier, new ServiceSectionSyncService);

        $alignJob = new AlignWithOos($processingLog);
        $alignJob->handle(app(OosAlignmentService::class));

        $processingLog->refresh();
        $section = ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->firstOrFail();

        $this->assertSame($churchService->id, $processingLog->church_service_id);
        $this->assertSame($song->id, $section->church_service_item_id);
        $this->assertSame('Opening Song', $section->title);
        $this->assertSame('inferred', $section->metadata['oos_alignment']['song_match_type'] ?? null);
        $this->assertSame('oos_order_inference', $section->metadata['oos_alignment']['song_match_strategy']);
        $this->assertTrue($churchService->fresh()->needs_review);
    }

    #[Test]
    public function late_oos_reconciliation_updates_existing_sections_without_full_reprocessing(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->completed()->create([
            'church_service_id' => null,
            'extracted_date' => '2026-08-16',
            'extracted_service' => SermonService::MORNING->value,
            'current_step' => 'completed',
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::SONG->value,
            'section_order' => 1,
            'title' => null,
            'confidence' => 0.5,
            'metadata' => [
                'confidence_level' => 'low',
                'classification_mode' => 'audio_only',
            ],
        ]);

        $churchService = ChurchService::factory()->create([
            'date' => '2026-08-16',
            'service' => SermonService::MORNING->value,
        ]);

        $song = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Closing Song',
        ]);

        $job = new ReconcileServiceSections($processingLog, $churchService);
        $job->handle(new MediaProcessingIdentityResolver, app(OosAlignmentService::class));

        $processingLog->refresh();
        $section->refresh();

        $this->assertSame($churchService->id, $processingLog->church_service_id);
        $this->assertSame($song->id, $section->church_service_item_id);
        $this->assertSame('Closing Song', $section->title);
    }
}
