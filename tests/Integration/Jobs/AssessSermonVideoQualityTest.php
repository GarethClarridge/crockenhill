<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Data\SermonVideoQualityAssessmentResult;
use App\Enums\SermonVideoQualityStatus;
use App\Jobs\AssessSermonVideoQuality;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\Media\MediaDiskReachability;
use App\Services\Media\Video\FrameExtractionService;
use App\Services\Media\Video\SermonVideoQualityAssessmentService;
use App\Services\Sermon\SermonExposurePolicy;
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

        $frameExtractionService = $this->createStub(FrameExtractionService::class);
        $exposurePolicy = $this->createStub(SermonExposurePolicy::class);

        (new AssessSermonVideoQuality($log))->handle($service, $frameExtractionService, $exposurePolicy, new MediaDiskReachability);

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

        $frameExtractionService = $this->createStub(FrameExtractionService::class);
        $exposurePolicy = $this->createStub(SermonExposurePolicy::class);

        (new AssessSermonVideoQuality(sermonId: $sermon->id))->handle($service, $frameExtractionService, $exposurePolicy, new MediaDiskReachability);

        $sermon->refresh();

        $this->assertSame(SermonVideoQualityStatus::Unassessed, $sermon->video_quality_status);
        $this->assertSame('analysis_failed', $sermon->video_quality_reason);
    }

    #[Test]
    public function it_assesses_the_video_on_the_sermons_own_asset_disk(): void
    {
        config(['media-processing.storage.sermon_disk' => 'historic_staging']);

        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/977/video.mp4',
            'asset_disk' => 'historic_quarantine',
        ]);

        $service = $this->createMock(SermonVideoQualityAssessmentService::class);
        $service->expects($this->once())
            ->method('assessAndRetainLocalPath')
            ->with(
                $this->anything(),
                'sermons/977/video.mp4',
                'historic_quarantine',
            )
            ->willReturn([
                'result' => $this->approvedResult(),
                'localVideoPath' => null,
            ]);

        (new AssessSermonVideoQuality(sermonId: $sermon->id))->handle(
            $service,
            $this->createStub(FrameExtractionService::class),
            $this->createStub(SermonExposurePolicy::class),
            new MediaDiskReachability,
        );

        $sermon->refresh();

        $this->assertSame(SermonVideoQualityStatus::Approved, $sermon->video_quality_status);
    }

    #[Test]
    public function it_falls_back_to_the_configured_disk_when_the_sermon_records_none(): void
    {
        config(['media-processing.storage.sermon_disk' => 'public']);

        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/video.mp4',
            'asset_disk' => null,
        ]);

        $service = $this->createMock(SermonVideoQualityAssessmentService::class);
        $service->expects($this->once())
            ->method('assessAndRetainLocalPath')
            ->with($this->anything(), 'sermons/video.mp4', 'public')
            ->willReturn([
                'result' => $this->approvedResult(),
                'localVideoPath' => null,
            ]);

        (new AssessSermonVideoQuality(sermonId: $sermon->id))->handle(
            $service,
            $this->createStub(FrameExtractionService::class),
            $this->createStub(SermonExposurePolicy::class),
            new MediaDiskReachability,
        );

        $this->assertSame(SermonVideoQualityStatus::Approved, $sermon->refresh()->video_quality_status);
    }

    #[Test]
    public function it_leaves_an_existing_verdict_alone_when_the_owning_disk_is_unreachable(): void
    {
        config(['filesystems.disks.detached_volume' => [
            'driver' => 'local',
            'root' => '/nonexistent/detached-volume',
        ]]);

        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/977/video.mp4',
            'asset_disk' => 'detached_volume',
            'video_quality_status' => SermonVideoQualityStatus::Approved,
            'video_quality_reason' => null,
        ]);

        $service = $this->createMock(SermonVideoQualityAssessmentService::class);
        $service->expects($this->never())->method('assessAndRetainLocalPath');

        (new AssessSermonVideoQuality(sermonId: $sermon->id))->handle(
            $service,
            $this->createStub(FrameExtractionService::class),
            $this->createStub(SermonExposurePolicy::class),
            new MediaDiskReachability,
        );

        $sermon->refresh();

        $this->assertSame(SermonVideoQualityStatus::Approved, $sermon->video_quality_status);
        $this->assertNull($sermon->video_quality_reason);
    }

    #[Test]
    public function an_unreachable_disk_never_becomes_a_missing_file_verdict(): void
    {
        config(['filesystems.disks.detached_volume' => [
            'driver' => 'local',
            'root' => '/nonexistent/detached-volume',
        ]]);

        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/977/video.mp4',
            'asset_disk' => 'detached_volume',
            'video_quality_status' => SermonVideoQualityStatus::Unassessed,
            'video_quality_reason' => null,
        ]);

        (new AssessSermonVideoQuality(sermonId: $sermon->id))->handle(
            $this->createStub(SermonVideoQualityAssessmentService::class),
            $this->createStub(FrameExtractionService::class),
            $this->createStub(SermonExposurePolicy::class),
            new MediaDiskReachability,
        );

        $sermon->refresh();

        $this->assertSame(SermonVideoQualityStatus::Unassessed, $sermon->video_quality_status);
        $this->assertNull($sermon->video_quality_reason);
        $this->assertNull($sermon->video_quality_assessed_at);
    }

    #[Test]
    public function a_settled_verdict_survives_a_video_file_that_can_no_longer_be_read(): void
    {
        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/842/video.mp4',
            'video_quality_status' => SermonVideoQualityStatus::Approved,
            'video_quality_reason' => null,
            'video_quality_assessed_at' => now()->subMonth(),
        ]);

        $service = $this->createMock(SermonVideoQualityAssessmentService::class);
        $service->method('assessAndRetainLocalPath')->willReturn([
            'result' => SermonVideoQualityAssessmentResult::failed('missing_video_file'),
            'localVideoPath' => null,
        ]);

        (new AssessSermonVideoQuality(sermonId: $sermon->id))->handle(
            $service,
            $this->createStub(FrameExtractionService::class),
            $this->createStub(SermonExposurePolicy::class),
            new MediaDiskReachability,
        );

        $sermon->refresh();

        $this->assertSame(SermonVideoQualityStatus::Approved, $sermon->video_quality_status);
        $this->assertNull($sermon->video_quality_reason);
    }

    #[Test]
    public function a_never_assessed_sermon_still_records_a_missing_file(): void
    {
        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/842/video.mp4',
            'video_quality_status' => SermonVideoQualityStatus::Unassessed,
            'video_quality_reason' => null,
        ]);

        $service = $this->createMock(SermonVideoQualityAssessmentService::class);
        $service->method('assessAndRetainLocalPath')->willReturn([
            'result' => SermonVideoQualityAssessmentResult::failed('missing_video_file'),
            'localVideoPath' => null,
        ]);

        (new AssessSermonVideoQuality(sermonId: $sermon->id))->handle(
            $service,
            $this->createStub(FrameExtractionService::class),
            $this->createStub(SermonExposurePolicy::class),
            new MediaDiskReachability,
        );

        $sermon->refresh();

        $this->assertSame(SermonVideoQualityStatus::Unassessed, $sermon->video_quality_status);
        $this->assertSame('missing_video_file', $sermon->video_quality_reason);
    }

    private function approvedResult(): SermonVideoQualityAssessmentResult
    {
        return new SermonVideoQualityAssessmentResult(
            status: SermonVideoQualityStatus::Approved,
            reason: null,
            sampleCount: 8,
            sampleTimestamps: [24.0, 48.0],
            blankFrameRatio: 0.0,
            frozenPairRatio: 0.0,
            lowDetailRatio: 0.0,
            aggregateScore: 0.9,
        );
    }
}
