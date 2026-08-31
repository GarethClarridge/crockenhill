<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Enums\ProcessingStatus;
use App\Enums\ServiceSectionPublicationStatus;
use App\Jobs\CleanupTemporaryFiles;
use App\Jobs\StoreSermonVideo;
use App\Models\HistoricImportNestedJob;
use App\Models\MediaProcessingLog;
use App\Models\SermonProcessingStep;
use App\Models\ServiceSection;
use App\Services\Media\Video\VideoStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class CleanupTemporaryFilesTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    #[Test]
    public function it_collects_source_file_path_for_cleanup(): void
    {
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'source_file_path' => 'temp/source-audio.mp3',
        ]);

        $mockStorage = $this->createMock(VideoStorageService::class);
        $mockStorage->expects($this->once())
            ->method('cleanupTemporaryFiles')
            ->with($this->callback(function (array $files) {
                return in_array('temp/source-audio.mp3', $files);
            }));

        Log::shouldReceive('info')->atLeast()->once();

        $job = new CleanupTemporaryFiles($log);
        $job->handle($mockStorage);

        $log->refresh();
        $this->assertEquals('completed', $log->status->value);
    }

    #[Test]
    #[DataProvider('reviewSourceRetentionCases')]
    public function it_retains_the_source_for_each_current_review_signal(
        ProcessingStatus $status,
        string $currentStep,
        ?array $sectionAttributes,
        ?array $processingMetadata,
    ): void {
        $sourcePath = 'temp/source-video.mp4';
        $log = MediaProcessingLog::factory()->livestream()->create([
            'status' => $status,
            'current_step' => $currentStep,
            'source_file_path' => $sourcePath,
            'enhanced_audio_file_path' => 'temp/enhanced-audio.mp3',
            'processing_metadata' => $processingMetadata,
        ]);

        if ($sectionAttributes !== null) {
            ServiceSection::factory()->create([
                'media_processing_log_id' => $log->id,
                ...$sectionAttributes,
            ]);
        }

        $mockStorage = $this->createMock(VideoStorageService::class);
        $mockStorage->expects($this->once())
            ->method('cleanupTemporaryFiles')
            ->with($this->callback(static function (array $files) use ($sourcePath): bool {
                return ! in_array($sourcePath, $files, true)
                    && in_array('temp/enhanced-audio.mp3', $files, true);
            }));

        Log::shouldReceive('info')->atLeast()->once();

        (new CleanupTemporaryFiles($log))->handle($mockStorage);

        $expectedStatus = $status === ProcessingStatus::Failed
            ? ProcessingStatus::Failed
            : ProcessingStatus::Completed;

        $this->assertSame($expectedStatus, $log->fresh()->status);
    }

    /**
     * @return array<string, array{ProcessingStatus, string, array<string, mixed>|null, array<string, mixed>|null}>
     */
    public static function reviewSourceRetentionCases(): array
    {
        return [
            'pending section approval' => [
                ProcessingStatus::Completed,
                'completed',
                ['publication_status' => ServiceSectionPublicationStatus::PendingApproval],
                null,
            ],
            'section manual review flag' => [
                ProcessingStatus::Completed,
                'completed',
                ['needs_manual_review' => true],
                null,
            ],
            'video quality needs review' => [
                ProcessingStatus::Completed,
                'completed',
                null,
                ['video_quality' => ['status' => 'needs_review']],
            ],
            'run manual review status' => [
                ProcessingStatus::Failed,
                'manual_review_required',
                null,
                null,
            ],
        ];
    }

    #[Test]
    public function it_collects_metadata_temp_paths_for_cleanup(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'source_file_path' => null,
            'processing_metadata' => [
                'extracted_segment_path' => 'temp/segment.mp4',
                'extracted_audio_path' => 'temp/audio.mp3',
                'temp_video_path' => 'temp/video.mp4',
            ],
        ]);

        $mockStorage = $this->createMock(VideoStorageService::class);
        $mockStorage->expects($this->once())
            ->method('cleanupTemporaryFiles')
            ->with($this->callback(function (array $files) {
                return in_array('temp/segment.mp4', $files)
                    && in_array('temp/audio.mp3', $files)
                    && in_array('temp/video.mp4', $files);
            }));

        Log::shouldReceive('info')->atLeast()->once();

        $job = new CleanupTemporaryFiles($log);
        $job->handle($mockStorage);

        $log->refresh();
        $this->assertEquals('completed', $log->status->value);
    }

    #[Test]
    public function it_includes_stored_file_for_video_type_in_temp_directory(): void
    {
        // stored_file_path is an alias for source_file_path
        $log = MediaProcessingLog::factory()->video()->processing()->create([
            'source_file_path' => 'temp/stored-video.mp4',
        ]);

        $mockStorage = $this->createMock(VideoStorageService::class);
        $mockStorage->expects($this->once())
            ->method('cleanupTemporaryFiles')
            ->with($this->callback(function (array $files) {
                return in_array('temp/stored-video.mp4', $files);
            }));

        Log::shouldReceive('info')->atLeast()->once();

        $job = new CleanupTemporaryFiles($log);
        $job->handle($mockStorage);
    }

    #[Test]
    public function it_does_not_include_stored_file_for_video_outside_temp(): void
    {
        // Note: stored_file_path is an accessor alias for source_file_path
        // so when the video check reads stored_file_path it reads source_file_path
        $log = MediaProcessingLog::factory()->video()->processing()->create([
            'source_file_path' => 'sermons/permanent-video.mp4',
        ]);

        $mockStorage = $this->createMock(VideoStorageService::class);
        $mockStorage->expects($this->once())
            ->method('cleanupTemporaryFiles')
            ->with($this->callback(function (array $files) {
                // source_file_path is always collected (line 37-39),
                // but the video temp/ check should NOT add it a second time
                return count(array_keys($files, 'sermons/permanent-video.mp4')) === 1;
            }));

        Log::shouldReceive('info')->atLeast()->once();

        $job = new CleanupTemporaryFiles($log);
        $job->handle($mockStorage);
    }

    #[Test]
    public function it_handles_empty_metadata_gracefully(): void
    {
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'source_file_path' => null,
        ]);

        // Ensure no metadata is set
        $log->update(['processing_metadata' => null]);

        $mockStorage = $this->createMock(VideoStorageService::class);
        $mockStorage->expects($this->once())
            ->method('cleanupTemporaryFiles')
            ->with([]);

        Log::shouldReceive('info')->atLeast()->once();

        $job = new CleanupTemporaryFiles($log);
        $job->handle($mockStorage);

        $log->refresh();
        $this->assertEquals('completed', $log->status->value);
    }

    #[Test]
    public function it_marks_as_completed_even_on_cleanup_error(): void
    {
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'source_file_path' => 'temp/some-file.mp3',
        ]);

        $mockStorage = $this->createMock(VideoStorageService::class);
        $mockStorage->expects($this->once())
            ->method('cleanupTemporaryFiles')
            ->willThrowException(new \Exception('Cleanup failed'));

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->once();

        $job = new CleanupTemporaryFiles($log);
        $job->handle($mockStorage);

        $log->refresh();
        $this->assertEquals('completed', $log->status->value);
    }

    #[Test]
    public function it_preserves_notification_failure_context_when_marking_completed(): void
    {
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'source_file_path' => 'temp/source-audio.mp3',
            'current_step' => 'notification_failed',
            'error_message' => 'Notification failed: SMTP transport unavailable',
        ]);

        $mockStorage = $this->createMock(VideoStorageService::class);
        $mockStorage->expects($this->once())
            ->method('cleanupTemporaryFiles')
            ->with($this->callback(function (array $files) {
                return in_array('temp/source-audio.mp3', $files, true);
            }));

        Log::shouldReceive('info')->atLeast()->once();

        $job = new CleanupTemporaryFiles($log);
        $job->handle($mockStorage);

        $log->refresh();

        $this->assertEquals('completed', $log->status->value);
        $this->assertSame('notification_failed', $log->current_step);
        $this->assertSame(
            'Notification failed: SMTP transport unavailable',
            $log->error_message
        );
    }

    #[Test]
    public function it_runs_cleanup_but_does_not_overwrite_cancelled_status(): void
    {
        $log = MediaProcessingLog::factory()->audio()->cancelled()->create([
            'source_file_path' => 'temp/source-audio.mp3',
        ]);

        $mockStorage = $this->createMock(VideoStorageService::class);
        $mockStorage->expects($this->once())
            ->method('cleanupTemporaryFiles')
            ->with($this->callback(fn (array $files) => in_array('temp/source-audio.mp3', $files, true)));

        Log::shouldReceive('info')->atLeast()->once();

        $job = new CleanupTemporaryFiles($log);
        $job->handle($mockStorage);

        $log->refresh();
        $this->assertEquals(ProcessingStatus::Cancelled, $log->status);
        $this->assertEquals('cancelled', $log->current_step);
        $this->assertNull($log->completed_at);
    }

    #[Test]
    public function it_has_correct_job_configuration(): void
    {
        $log = MediaProcessingLog::factory()->create();

        $job = new CleanupTemporaryFiles($log);

        $this->assertEquals(1, $job->tries);
        $this->assertEquals(300, $job->timeout);
    }

    #[Test]
    public function it_refuses_to_delete_working_copies_while_historic_video_storage_is_unsettled(): void
    {
        $operation = $this->createHistoricImportOperation();
        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'historic_import_operation_id' => $operation->id,
            'source_file_path' => 'temp/source-video.mp4',
            'video_file_path' => 'temp/sermon-video.mp4',
            'enhanced_audio_file_path' => 'temp/enhanced-audio.mp3',
        ]);
        HistoricImportNestedJob::query()->create([
            'historic_import_operation_id' => $operation->id,
            'media_processing_log_id' => $log->id,
            'job_key' => StoreSermonVideo::nestedJobKey($log->processing_id),
            'job_type' => StoreSermonVideo::class,
            'state' => 'retryable',
            'attempts' => 1,
            'dispatched_at' => now(),
        ]);

        Queue::fake();

        $mockStorage = $this->createMock(VideoStorageService::class);
        $mockStorage->expects($this->never())->method('cleanupTemporaryFiles');

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->once();

        (new CleanupTemporaryFiles($log))->handle($mockStorage);

        $log->refresh();
        $this->assertSame('processing', $log->status->value);
        Queue::assertPushed(CleanupTemporaryFiles::class);
    }

    #[Test]
    public function it_refuses_to_delete_working_copies_while_historic_speaker_work_is_active(): void
    {
        $operation = $this->createHistoricImportOperation();
        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'historic_import_operation_id' => $operation->id,
            'source_file_path' => 'temp/source-video.mp4',
        ]);
        SermonProcessingStep::factory()->create([
            'processing_id' => $log->processing_id,
            'step' => 'identifying_speaker',
            'status' => ProcessingStatus::Started,
        ]);

        Queue::fake();

        $mockStorage = $this->createMock(VideoStorageService::class);
        $mockStorage->expects($this->never())->method('cleanupTemporaryFiles');

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->once();

        (new CleanupTemporaryFiles($log))->handle($mockStorage);

        $log->refresh();
        $this->assertSame('processing', $log->status->value);
        Queue::assertPushed(CleanupTemporaryFiles::class);
    }

    #[Test]
    public function it_fails_the_run_rather_than_stranding_it_when_storage_failed_permanently(): void
    {
        $operation = $this->createHistoricImportOperation();
        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'historic_import_operation_id' => $operation->id,
            'source_file_path' => 'temp/source-video.mp4',
        ]);
        HistoricImportNestedJob::query()->create([
            'historic_import_operation_id' => $operation->id,
            'media_processing_log_id' => $log->id,
            'job_key' => StoreSermonVideo::nestedJobKey($log->processing_id),
            'job_type' => StoreSermonVideo::class,
            'state' => 'failed',
            'attempts' => 3,
            'dispatched_at' => now(),
        ]);

        Queue::fake();

        $mockStorage = $this->createMock(VideoStorageService::class);
        $mockStorage->expects($this->never())->method('cleanupTemporaryFiles');

        (new CleanupTemporaryFiles($log))->handle($mockStorage);

        $log->refresh();
        $this->assertSame('failed', $log->status->value);
        $this->assertStringContainsString('never settled', (string) $log->error_message);
        Queue::assertNotPushed(CleanupTemporaryFiles::class);
    }

    #[Test]
    public function it_fails_the_run_once_the_historic_deferral_budget_is_exhausted(): void
    {
        $operation = $this->createHistoricImportOperation();
        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'historic_import_operation_id' => $operation->id,
            'source_file_path' => 'temp/source-video.mp4',
        ]);
        HistoricImportNestedJob::query()->create([
            'historic_import_operation_id' => $operation->id,
            'media_processing_log_id' => $log->id,
            'job_key' => StoreSermonVideo::nestedJobKey($log->processing_id),
            'job_type' => StoreSermonVideo::class,
            'state' => 'retryable',
            'attempts' => 1,
            'dispatched_at' => now(),
        ]);

        Queue::fake();

        $mockStorage = $this->createMock(VideoStorageService::class);
        $mockStorage->expects($this->never())->method('cleanupTemporaryFiles');

        (new CleanupTemporaryFiles($log, historicDeferrals: 12))->handle($mockStorage);

        $log->refresh();
        $this->assertSame('failed', $log->status->value);
        Queue::assertNotPushed(CleanupTemporaryFiles::class);
    }

    #[Test]
    public function it_does_not_defer_cleanup_for_an_ordinary_livestream_run(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'source_file_path' => 'temp/source-video.mp4',
        ]);
        SermonProcessingStep::factory()->create([
            'processing_id' => $log->processing_id,
            'step' => 'identifying_speaker',
            'status' => ProcessingStatus::Started,
        ]);

        Queue::fake();

        $mockStorage = $this->createMock(VideoStorageService::class);
        $mockStorage->expects($this->once())->method('cleanupTemporaryFiles');

        (new CleanupTemporaryFiles($log))->handle($mockStorage);

        $log->refresh();
        $this->assertSame('completed', $log->status->value);
        Queue::assertNotPushed(CleanupTemporaryFiles::class);
    }

    #[Test]
    public function it_cleans_up_after_a_non_blocking_historic_speaker_failure(): void
    {
        $operation = $this->createHistoricImportOperation();
        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'historic_import_operation_id' => $operation->id,
            'source_file_path' => 'temp/source-video.mp4',
        ]);

        // IdentifySpeaker records a deterministic failure and lets the chain
        // continue, so the working copies it used are released, not in flight.
        SermonProcessingStep::factory()->create([
            'processing_id' => $log->processing_id,
            'step' => 'identifying_speaker',
            'status' => ProcessingStatus::Failed,
        ]);

        Queue::fake();

        $mockStorage = $this->createMock(VideoStorageService::class);
        $mockStorage->expects($this->once())->method('cleanupTemporaryFiles');

        (new CleanupTemporaryFiles($log))->handle($mockStorage);

        $log->refresh();
        $this->assertSame('completed', $log->status->value);
        Queue::assertNotPushed(CleanupTemporaryFiles::class);
    }
}
