<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Enums\SermonService;
use App\Jobs\ReconcileServiceSections;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\MediaProcessingIdentityResolver;
use App\Services\OosAlignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReconcileServiceSectionsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_links_and_reconciles_existing_service_sections_without_restarting_processing(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-05-10',
            'service' => SermonService::Morning->value,
        ]);

        $song = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Opening Song',
        ]);

        $prayer = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 2,
            'type' => 'custom',
            'title' => 'Opening Prayer',
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->completed()->create([
            'current_step' => 'completed',
            'church_service_id' => null,
            'extracted_date' => '2026-05-10',
            'extracted_service' => SermonService::Morning->value,
        ]);

        $songSegment = LivestreamSegment::factory()->song()->create([
            'media_processing_log_id' => $processingLog->id,
            'segment_order' => 1,
            'start_time' => 0,
            'end_time' => 210,
            'duration' => 210,
        ]);

        $prayerSegment = LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $processingLog->id,
            'segment_order' => 2,
            'start_time' => 210,
            'end_time' => 360,
            'duration' => 150,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => 'song',
            'section_order' => 1,
            'title' => 'Opening Song',
            'start_time' => 0.0,
            'end_time' => 210.0,
            'duration' => 210.0,
            'source_segment_ids' => [$songSegment->id],
            'metadata' => [
                'confidence_level' => 'low',
                'classification_mode' => 'audio_only',
            ],
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => 'prayer',
            'section_order' => 2,
            'title' => null,
            'start_time' => 210.0,
            'end_time' => 360.0,
            'duration' => 150.0,
            'source_segment_ids' => [$prayerSegment->id],
            'metadata' => [
                'confidence_level' => 'low',
                'classification_mode' => 'ai_transcript',
            ],
        ]);

        $job = new ReconcileServiceSections($processingLog, $churchService);
        $job->handle(
            new MediaProcessingIdentityResolver,
            app(OosAlignmentService::class),
        );

        $processingLog->refresh();
        $songSection = ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->where('section_order', 1)
            ->firstOrFail();
        $prayerSection = ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->where('section_order', 2)
            ->firstOrFail();

        $this->assertSame($churchService->id, $processingLog->church_service_id);
        $this->assertSame('completed', $processingLog->status->value);
        $this->assertSame('completed', $processingLog->current_step);
        $this->assertSame(2, $processingLog->serviceSections()->count());
        $this->assertSame($song->id, $songSection->church_service_item_id);
        $this->assertSame('song', $songSection->section_type->value);
        $this->assertSame('Opening Song', $songSection->title);
        $this->assertSame(0.0, $songSection->start_time);
        $this->assertSame(210.0, $songSection->end_time);
        $this->assertFalse($songSection->needs_manual_review);
        $this->assertSame([$songSegment->id], $songSection->source_segment_ids);
        $this->assertGreaterThanOrEqual(0.85, $songSection->confidence);

        $this->assertSame($prayer->id, $prayerSection->church_service_item_id);
        $this->assertSame('prayer', $prayerSection->section_type->value);
        $this->assertSame('Opening Prayer', $prayerSection->title);
        $this->assertSame(210.0, $prayerSection->start_time);
        $this->assertSame(360.0, $prayerSection->end_time);
        $this->assertFalse($prayerSection->needs_manual_review);
        $this->assertSame([$prayerSegment->id], $prayerSection->source_segment_ids);
        $this->assertGreaterThanOrEqual(0.85, $prayerSection->confidence);
        $this->assertTrue($churchService->fresh()->needs_review);
    }

    #[Test]
    public function it_links_the_processing_log_but_keeps_existing_sections_when_service_items_are_not_ready(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-05-17',
            'service' => SermonService::Morning->value,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->completed()->create([
            'current_step' => 'completed',
            'church_service_id' => null,
            'extracted_date' => '2026-05-17',
            'extracted_service' => SermonService::Morning->value,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_order' => 1,
            'title' => 'Existing Section',
        ]);

        $job = new ReconcileServiceSections($processingLog, $churchService);
        $job->handle(
            new MediaProcessingIdentityResolver,
            app(OosAlignmentService::class),
        );

        $processingLog->refresh();

        $this->assertSame($churchService->id, $processingLog->church_service_id);
        $this->assertSame(1, $processingLog->serviceSections()->count());
        $this->assertDatabaseHas('service_sections', [
            'media_processing_log_id' => $processingLog->id,
            'section_order' => 1,
            'title' => 'Existing Section',
        ]);
    }
}
