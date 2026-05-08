<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Data\SermonVideoQualityAssessmentResult;
use App\Enums\SermonVideoQualityStatus;
use App\Jobs\AssessSermonVideoQuality;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\FrameExtractionService;
use App\Services\SermonExposurePolicy;
use App\Services\SermonVideoQualityAssessmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AssessSermonVideoQualityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_persists_sermon_summary_and_processing_metadata(): void
    {
        $sermon = Sermon::factory()->create(['video_file_path' => 'sermons/video.mp4']);
        $log = MediaProcessingLog::factory()->video()->processing()->create([
            'sermon_id' => $sermon->id,
            'video_file_path' => 'sermons/video.mp4',
        ]);

        $assessmentResult = new SermonVideoQualityAssessmentResult(
            status: SermonVideoQualityStatus::Rejected,
            reason: 'mostly_black',
            sampleCount: 8,
            sampleTimestamps: [24.0, 48.0],
            blankFrameRatio: 1.0,
            frozenPairRatio: 0.0,
            lowDetailRatio: 1.0,
            aggregateScore: 0.01,
        );

        $service = $this->createMock(SermonVideoQualityAssessmentService::class);
        $service->expects($this->once())
            ->method('assessAndRetainLocalPath')
            ->willReturn(['result' => $assessmentResult, 'localVideoPath' => null]);

        $frameExtractionService = $this->createMock(FrameExtractionService::class);
        $exposurePolicy = $this->createMock(SermonExposurePolicy::class);

        (new AssessSermonVideoQuality($log))->handle($service, $frameExtractionService, $exposurePolicy);

        $sermon->refresh();
        $log->refresh();

        $this->assertSame(SermonVideoQualityStatus::Rejected, $sermon->video_quality_status);
        $this->assertSame('mostly_black', $sermon->video_quality_reason);
        $this->assertNotNull($sermon->video_quality_assessed_at);
        $this->assertSame('rejected', $log->videoQualityMetadata()['status']);
        $this->assertSame('mostly_black', $log->videoQualityMetadata()['reason']);
    }

    #[Test]
    public function it_records_analysis_failed_without_throwing_when_service_errors(): void
    {
        $sermon = Sermon::factory()->create(['video_file_path' => 'sermons/video.mp4']);

        $service = $this->createMock(SermonVideoQualityAssessmentService::class);
        $service->method('assessAndRetainLocalPath')->willThrowException(new \RuntimeException('boom'));

        $frameExtractionService = $this->createMock(FrameExtractionService::class);
        $exposurePolicy = $this->createMock(SermonExposurePolicy::class);

        (new AssessSermonVideoQuality(sermonId: $sermon->id))->handle($service, $frameExtractionService, $exposurePolicy);

        $sermon->refresh();

        $this->assertSame(SermonVideoQualityStatus::Unassessed, $sermon->video_quality_status);
        $this->assertSame('analysis_failed', $sermon->video_quality_reason);
    }
}
