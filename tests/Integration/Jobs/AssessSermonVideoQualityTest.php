<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Data\SermonVideoQualityAssessmentResult;
use App\Enums\SermonVideoQualityStatus;
use App\Enums\ServiceSectionType;
use App\Jobs\AssessSermonVideoQuality;
use App\Jobs\StoreSermonVideo;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Presenters\SermonViewPresenter;
use App\Services\Media\MediaDiskReachability;
use App\Services\Media\RecordedVideoOutput;
use App\Services\Media\Video\FrameExtractionService;
use App\Services\Media\Video\SermonVideoQualityAssessmentService;
use App\Services\Processing\ProcessingPipelineBuilder;
use App\Services\Processing\SermonMetadataIntegrationService;
use App\Services\Processing\StorageAdapterHelper;
use App\Services\Sermon\SermonExposurePolicy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AssessSermonVideoQualityTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('historic_quarantine');
    }

    #[Test]
    public function the_real_store_then_assess_chain_grades_the_new_file_after_the_request_is_spent(): void
    {
        config(['queue.default' => 'sync', 'media-processing.storage.sermon_disk' => 'historic_staging', 'media-processing.storage.temp_disk' => 'local']);
        Storage::fake('local');
        Storage::fake('historic_staging');
        Storage::fake('historic_quarantine');
        $sermon = Sermon::factory()->create(['asset_disk' => 'historic_quarantine']);
        $path = "sermons/{$sermon->id}/video.mp4";
        $sermon->update(['video_file_path' => $path]);
        Storage::disk('historic_quarantine')->put($path, 'stale prior quarantine cut');
        Storage::disk('local')->put('temp/fresh.mp4', 'fresh paired cut');
        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'sermon_id' => $sermon->id, 'video_file_path' => 'temp/fresh.mp4',
            'processing_metadata' => ['trim' => ['observed_duration' => 1253.666667, 'segments' => [['start_time' => 2472.78, 'end_time' => 2532.17], ['start_time' => 2594.88, 'end_time' => 3789.12]]]],
        ]);
        $log->putCorpusRerunStamp(['snapshot_file_sha256' => str_repeat('a', 64), 'code_revision' => str_repeat('b', 64), 'dispatched_at' => now()->toIso8601String(), 'extraction_dispatched_at' => now()->toIso8601String()]);
        $log->markAsReExtraction();
        $integration = Mockery::mock(SermonMetadataIntegrationService::class, [app(StorageAdapterHelper::class), app(SermonViewPresenter::class)])->makePartial();
        $integration->shouldReceive('validateVideoFile')->once()->andReturnTrue();
        $this->app->instance(SermonMetadataIntegrationService::class, $integration);
        $observed = null;
        $service = $this->createMock(SermonVideoQualityAssessmentService::class);
        $service->expects($this->once())->method('assessAndRetainLocalPath')->willReturnCallback(function ($sermon, $videoPath, $disk) use (&$observed): array {
            $observed = Storage::disk($disk)->get($videoPath);

            return ['result' => $this->approvedResult(), 'localVideoPath' => null];
        });
        $this->app->instance(SermonVideoQualityAssessmentService::class, $service);
        $this->app->instance(FrameExtractionService::class, $this->createStub(FrameExtractionService::class));
        $jobs = app(ProcessingPipelineBuilder::class)->buildLivestreamPostReviewChainJobs($log);
        $assessment = collect($jobs)->first(fn ($job): bool => $job instanceof AssessSermonVideoQuality);

        Bus::chain([new StoreSermonVideo($log, $sermon->id), $assessment])->dispatch();

        $this->assertFalse($log->refresh()->isReExtraction());
        $this->assertTrue($log->permitsPromotionVideoReplacement());
        $this->assertSame('fresh paired cut', $observed);
        $output = data_get($log->processing_metadata?->toArray(), 'media_outputs.sermon');
        $this->assertSame('historic_staging', $output['disk']);
        $this->assertSame($path, $output['path']);
        $this->assertSame(strlen('fresh paired cut'), $output['size']);
        $this->assertSame(hash('sha256', 'fresh paired cut'), $output['sha256']);
        $this->assertSame($output, $log->videoQualityMetadata()['graded_output']);
    }

    #[Test]
    #[DataProvider('invalidRecordedOutputs')]
    public function changed_bytes_at_the_recorded_path_are_an_error_without_grading_the_other_copy(string $change, string $reason): void
    {
        Storage::fake('historic_staging');
        Storage::fake('historic_quarantine');
        $path = 'sermons/936/video.mp4';
        Storage::disk('historic_staging')->put($path, 'fresh video!');
        Storage::disk('historic_quarantine')->put($path, 'stale but valid file');
        $sermon = Sermon::factory()->create(['video_file_path' => $path, 'asset_disk' => 'historic_quarantine']);
        $log = MediaProcessingLog::factory()->livestream()->processing()->create(['sermon_id' => $sermon->id, 'processing_metadata' => ['trim' => ['segments' => [['start_time' => 100, 'end_time' => 200], ['start_time' => 300, 'end_time' => 400]]]]]);
        $outputs = app(RecordedVideoOutput::class);
        $outputs->record($log, 'sermon', 'historic_staging', $path, $outputs->provenance($log));
        match ($change) {
            'hash' => Storage::disk('historic_staging')->put($path, 'other video!'),
            'size' => Storage::disk('historic_staging')->put($path, 'short'),
            'file' => Storage::disk('historic_staging')->delete($path),
            'record' => $log->writeProcessingMetadata(static function (array $metadata): array {
                unset($metadata['media_outputs']);

                return $metadata;
            }),
            'provenance' => $log->putCorpusRerunStamp(['snapshot_file_sha256' => str_repeat('c', 64)]),
            'span_order' => $log->writeProcessingMetadata(static function (array $metadata): array {
                $metadata['trim']['segments'] = array_reverse($metadata['trim']['segments']);

                return $metadata;
            }),
        };
        $service = $this->createMock(SermonVideoQualityAssessmentService::class);
        $service->expects($this->never())->method('assessAndRetainLocalPath');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($reason);
        try {
            (new AssessSermonVideoQuality($log))->handle($service, $this->createStub(FrameExtractionService::class), $this->createStub(SermonExposurePolicy::class), new MediaDiskReachability);
        } finally {
            $this->assertSame($reason, $log->refresh()->videoQualityMetadata()['reason']);
            $this->assertSame('unassessed', $log->videoQualityMetadata()['status']);
            $this->assertNull($log->videoQualityMetadata()['graded_output']);
        }
    }

    public static function invalidRecordedOutputs(): array
    {
        return [['hash', 'recorded_video_hash_mismatch'], ['size', 'recorded_video_size_mismatch'], ['file', 'recorded_video_file_missing'], ['record', 'recorded_video_output_missing'], ['provenance', 'recorded_video_provenance_mismatch'], ['span_order', 'recorded_video_provenance_mismatch']];
    }

    #[Test]
    public function a_recut_is_assessed_from_fresh_staging_bytes_before_quarantine_promotion(): void
    {
        config(['media-processing.storage.sermon_disk' => 'historic_staging']);
        Storage::fake('historic_staging');
        Storage::fake('historic_quarantine');
        $path = 'sermons/1311/video.mp4';
        Storage::disk('historic_quarantine')->put($path, 'stale prior cut');
        Storage::disk('historic_staging')->put($path, 'fresh paired cut');
        $sermon = Sermon::factory()->create(['video_file_path' => $path, 'asset_disk' => 'historic_quarantine']);
        $log = MediaProcessingLog::factory()->livestream()->processing()->create(['sermon_id' => $sermon->id]);
        $log->markAsReExtraction();
        app(RecordedVideoOutput::class)->record($log, 'sermon', 'historic_staging', $path, app(RecordedVideoOutput::class)->provenance($log));
        $observed = null;
        $service = $this->createMock(SermonVideoQualityAssessmentService::class);
        $service->method('assessAndRetainLocalPath')->willReturnCallback(function ($sermon, $videoPath, $disk) use (&$observed): array {
            $observed = Storage::disk($disk)->get($videoPath);

            return ['result' => $this->approvedResult(), 'localVideoPath' => null];
        });

        (new AssessSermonVideoQuality($log))->handle($service, $this->createStub(FrameExtractionService::class), $this->createStub(SermonExposurePolicy::class), new MediaDiskReachability);

        $this->assertSame('fresh paired cut', $observed);
        $this->assertSame('historic_staging', $log->refresh()->videoQualityMetadata()['asset_disk'] ?? null);
        $this->assertSame($path, $log->videoQualityMetadata()['video_path'] ?? null);
    }

    /**
     * `sermons:assess-video-quality` dispatches with a sermon id only, and 13
     * historic verdicts written that way left their evidence in laravel.log
     * alone. The verdict belongs on the run that published the sermon.
     */
    #[Test]
    public function a_sermon_only_assessment_records_its_verdict_on_the_run_that_published_the_sermon(): void
    {
        $sermon = Sermon::factory()->create(['video_file_path' => 'sermons/video.mp4', 'livestream_processing_id' => null]);
        $owningRun = MediaProcessingLog::factory()->livestream()->create();
        ServiceSection::factory()->create([
            'media_processing_log_id' => $owningRun->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'published_sermon_id' => $sermon->id,
        ]);
        $this->recordAssessmentOutput($sermon, $owningRun);

        $service = $this->createMock(SermonVideoQualityAssessmentService::class);
        $service->method('assessAndRetainLocalPath')->willReturn([
            'result' => new SermonVideoQualityAssessmentResult(
                status: SermonVideoQualityStatus::Rejected,
                reason: 'frozen_frames',
                durationSeconds: 1800.0,
                deadSeconds: 1800.0,
                usableShare: 0.0,
                freezeSeconds: 180.0,
                blackSeconds: 0.0,
            ),
            'localVideoPath' => '/tmp/assess-owning-run.mp4',
        ]);

        // No thumbnail job follows a command run, so the job must still clean
        // up its local copy rather than hand it to one through the run.
        $exposurePolicy = $this->createStub(SermonExposurePolicy::class);
        $exposurePolicy->method('shouldGenerateVideoThumbnail')->willReturn(true);
        $frameExtractionService = $this->createMock(FrameExtractionService::class);
        $frameExtractionService->expects($this->once())
            ->method('cleanupDownloadedVideo')
            ->with('/tmp/assess-owning-run.mp4');

        (new AssessSermonVideoQuality(sermonId: $sermon->id))->handle($service, $frameExtractionService, $exposurePolicy, new MediaDiskReachability);

        $owningRun->refresh();

        $this->assertSame('rejected', $owningRun->videoQualityMetadata()['status'] ?? null);
        $this->assertSame('frozen_frames', $owningRun->videoQualityMetadata()['reason'] ?? null);
        $this->assertArrayNotHasKey('cached_local_video_path', $owningRun->processing_metadata?->toArray() ?? []);
    }

    #[Test]
    public function a_sermon_only_assessment_falls_back_to_the_sermons_livestream_run(): void
    {
        $owningRun = MediaProcessingLog::factory()->livestream()->create(['processing_id' => 'assess-livestream-run']);
        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/video.mp4',
            'livestream_processing_id' => 'assess-livestream-run',
        ]);
        $this->recordAssessmentOutput($sermon, $owningRun);

        $service = $this->createMock(SermonVideoQualityAssessmentService::class);
        $service->method('assessAndRetainLocalPath')->willReturn([
            'result' => $this->approvedResult(),
            'localVideoPath' => null,
        ]);

        (new AssessSermonVideoQuality(sermonId: $sermon->id))->handle(
            $service,
            $this->createStub(FrameExtractionService::class),
            $this->createStub(SermonExposurePolicy::class),
            new MediaDiskReachability,
        );

        $this->assertSame('approved', $owningRun->fresh()->videoQualityMetadata()['status'] ?? null);
    }

    #[Test]
    public function it_persists_sermon_summary_and_processing_metadata(): void
    {
        $sermon = Sermon::factory()->create(['video_file_path' => 'sermons/video.mp4']);
        $log = MediaProcessingLog::factory()->video()->processing()->create([
            'sermon_id' => $sermon->id,
            'video_file_path' => 'sermons/video.mp4',
        ]);
        $this->recordAssessmentOutput($sermon, $log);

        $assessmentResult = new SermonVideoQualityAssessmentResult(
            status: SermonVideoQualityStatus::Rejected,
            reason: 'mostly_black',
            durationSeconds: 1800.0,
            deadSeconds: 1800.0,
            usableShare: 0.0,
            freezeSeconds: 180.0,
            blackSeconds: 174.0,
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
        $this->recordAssessmentOutput($sermon);

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
        $this->recordAssessmentOutput($sermon);

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

        $this->recordAssessmentOutput($sermon);
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
    public function an_existing_verdict_does_not_allow_an_unrecorded_unreachable_file_to_be_graded(): void
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

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('recorded_video_output_missing');
        (new AssessSermonVideoQuality(sermonId: $sermon->id))->handle(
            $service,
            $this->createStub(FrameExtractionService::class),
            $this->createStub(SermonExposurePolicy::class),
            new MediaDiskReachability,
        );

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

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('recorded_video_output_missing');
        (new AssessSermonVideoQuality(sermonId: $sermon->id))->handle(
            $this->createStub(SermonVideoQualityAssessmentService::class),
            $this->createStub(FrameExtractionService::class),
            $this->createStub(SermonExposurePolicy::class),
            new MediaDiskReachability,
        );

    }

    #[Test]
    public function a_settled_verdict_does_not_mask_a_recorded_file_that_can_no_longer_be_read(): void
    {
        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/842/video.mp4',
            'video_quality_status' => SermonVideoQualityStatus::Approved,
            'video_quality_reason' => null,
            'video_quality_assessed_at' => now()->subMonth(),
        ]);

        $service = $this->createMock(SermonVideoQualityAssessmentService::class);
        $this->recordAssessmentOutput($sermon);
        $service->method('assessAndRetainLocalPath')->willReturn([
            'result' => SermonVideoQualityAssessmentResult::failed('missing_video_file'),
            'localVideoPath' => null,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('recorded_video_file_missing');
        (new AssessSermonVideoQuality(sermonId: $sermon->id))->handle(
            $service,
            $this->createStub(FrameExtractionService::class),
            $this->createStub(SermonExposurePolicy::class),
            new MediaDiskReachability,
        );

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
        $this->recordAssessmentOutput($sermon);
        $service->method('assessAndRetainLocalPath')->willReturn([
            'result' => SermonVideoQualityAssessmentResult::failed('missing_video_file'),
            'localVideoPath' => null,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('recorded_video_file_missing');
        (new AssessSermonVideoQuality(sermonId: $sermon->id))->handle(
            $service,
            $this->createStub(FrameExtractionService::class),
            $this->createStub(SermonExposurePolicy::class),
            new MediaDiskReachability,
        );

    }

    private function recordAssessmentOutput(Sermon $sermon, ?MediaProcessingLog $run = null): void
    {
        $run ??= MediaProcessingLog::factory()->livestream()->processing()->create(['sermon_id' => $sermon->id]);
        $sermon->update(['livestream_processing_id' => $run->processing_id]);
        $disk = $sermon->assetDisk();
        Storage::disk($disk)->put($sermon->video_file_path, 'recorded test video');
        $outputs = app(RecordedVideoOutput::class);
        $outputs->record($run, 'sermon', $disk, $sermon->video_file_path, $outputs->provenance($run));
    }

    private function approvedResult(): SermonVideoQualityAssessmentResult
    {
        return new SermonVideoQualityAssessmentResult(
            status: SermonVideoQualityStatus::Approved,
            reason: null,
            durationSeconds: 1800.0,
            deadSeconds: 0.0,
            usableShare: 1.0,
            freezeSeconds: 0.0,
            blackSeconds: 0.0,
        );
    }
}
