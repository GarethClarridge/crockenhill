<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Enums\SermonService;
use App\Enums\ServiceSectionType;
use App\Jobs\ProjectLivestreamServiceStructure;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Models\SermonProcessingStep;
use App\Models\ServiceSection;
use App\Services\LivestreamChurchServiceProjectionService;
use App\Support\ChurchServiceProcessingTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProjectLivestreamServiceStructureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();
    }

    #[Test]
    public function test_projects_service_structure_from_classified_sections(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'processing_id' => 'test-job-001',
            'status' => ProcessingStatus::PROCESSING,
            'extracted_date' => '2026-03-23',
            'extracted_service' => SermonService::MORNING->value,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::SONG,
            'section_order' => 1,
            'title' => 'Amazing Grace',
            'confidence' => 0.9,
        ]);

        $job = new ProjectLivestreamServiceStructure($log);
        $job->handle(app(LivestreamChurchServiceProjectionService::class));

        $step = SermonProcessingStep::query()
            ->where('processing_id', $log->processing_id)
            ->where('step', ChurchServiceProcessingTimeline::PROJECT_LIVESTREAM_SERVICE_STRUCTURE)
            ->first();

        $this->assertNotNull($step);
        $this->assertSame(ProcessingStatus::COMPLETED, $step->status);
        $this->assertStringContainsString('1 item(s)', $step->message);

        $this->assertSame(1, ChurchService::query()->count());
    }

    #[Test]
    public function test_skips_for_non_livestream_processing(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_id' => 'test-audio-001',
            'processing_type' => MediaType::Audio,
        ]);

        $job = new ProjectLivestreamServiceStructure($log);
        $job->handle(app(LivestreamChurchServiceProjectionService::class));

        $step = SermonProcessingStep::query()
            ->where('processing_id', $log->processing_id)
            ->where('step', ChurchServiceProcessingTimeline::PROJECT_LIVESTREAM_SERVICE_STRUCTURE)
            ->first();

        $this->assertNotNull($step);
        $this->assertSame(ProcessingStatus::SKIPPED, $step->status);
    }

    #[Test]
    public function test_skips_for_cancelled_processing(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'processing_id' => 'test-cancelled-001',
            'status' => ProcessingStatus::CANCELLED,
        ]);

        $job = new ProjectLivestreamServiceStructure($log);
        $job->handle(app(LivestreamChurchServiceProjectionService::class));

        $step = SermonProcessingStep::query()
            ->where('processing_id', $log->processing_id)
            ->where('step', ChurchServiceProcessingTimeline::PROJECT_LIVESTREAM_SERVICE_STRUCTURE)
            ->first();

        $this->assertNotNull($step);
        $this->assertSame(ProcessingStatus::SKIPPED, $step->status);
    }

    #[Test]
    public function test_logs_skipped_when_projection_is_not_applicable(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'processing_id' => 'test-no-identity-001',
            'extracted_date' => null,
            'extracted_service' => null,
            'processing_metadata' => null,
        ]);

        $job = new ProjectLivestreamServiceStructure($log);
        $job->handle(app(LivestreamChurchServiceProjectionService::class));

        $step = SermonProcessingStep::query()
            ->where('processing_id', $log->processing_id)
            ->where('step', ChurchServiceProcessingTimeline::PROJECT_LIVESTREAM_SERVICE_STRUCTURE)
            ->first();

        $this->assertNotNull($step);
        $this->assertSame(ProcessingStatus::SKIPPED, $step->status);
    }

    #[Test]
    public function test_logs_failure_on_exception(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'processing_id' => 'test-fail-001',
        ]);

        $job = new ProjectLivestreamServiceStructure($log);
        $job->failed(new \RuntimeException('Something went wrong'));

        $step = SermonProcessingStep::query()
            ->where('processing_id', $log->processing_id)
            ->where('step', ChurchServiceProcessingTimeline::PROJECT_LIVESTREAM_SERVICE_STRUCTURE)
            ->first();

        $this->assertNotNull($step);
        $this->assertSame(ProcessingStatus::FAILED, $step->status);
        $this->assertSame('Something went wrong', $step->message);
    }
}
