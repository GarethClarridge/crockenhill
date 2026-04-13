<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Enums\SermonService;
use App\Enums\ServiceSectionType;
use App\Jobs\AlignWithOos;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\OosAlignmentService;
use App\Support\ChurchServiceProcessingTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AlignWithOosTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_aligns_existing_sections_when_a_matching_service_exists(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-07-05',
            'service' => SermonService::Morning->value,
        ]);

        $song = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Opening Song',
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create([
            'extracted_date' => '2026-07-05',
            'extracted_service' => SermonService::Morning->value,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::SONG->value,
            'section_order' => 1,
            'title' => 'Opening Song',
            'confidence' => 0.5,
            'metadata' => [
                'confidence_level' => 'low',
                'classification_mode' => 'audio_only',
            ],
        ]);

        $job = new AlignWithOos($processingLog);
        $job->handle(app(OosAlignmentService::class));

        $processingLog->refresh();
        $section->refresh();

        $this->assertSame($churchService->id, $processingLog->church_service_id);
        $this->assertSame($song->id, $section->church_service_item_id);
        $this->assertSame('high', $section->metadata['confidence_level']);
        $this->assertDatabaseHas('sermon_processing_steps', [
            'processing_id' => $processingLog->processing_id,
            'step' => ChurchServiceProcessingTimeline::ALIGN_WITH_OOS,
            'status' => 'completed',
        ]);
    }

    #[Test]
    public function it_no_ops_without_a_matching_church_service(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create([
            'extracted_date' => '2026-07-12',
            'extracted_service' => SermonService::Morning->value,
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

        $job = new AlignWithOos($processingLog);
        $job->handle(app(OosAlignmentService::class));

        $processingLog->refresh();
        $section->refresh();

        $this->assertNull($processingLog->church_service_id);
        $this->assertNull($section->church_service_item_id);
        $this->assertNull($section->title);
        $this->assertSame('low', $section->metadata['confidence_level']);
    }
}
