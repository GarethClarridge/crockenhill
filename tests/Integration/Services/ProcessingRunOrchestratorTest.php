<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Enums\ProcessingStatus;
use App\Jobs\AssessSermonVideoQuality;
use App\Jobs\CleanupTemporaryFiles;
use App\Jobs\CreateSermonTranscriptFromService;
use App\Jobs\ExtractAudioFromVideo;
use App\Jobs\GenerateThumbnail;
use App\Jobs\PrepareSectionPublicationCandidates;
use App\Jobs\ProcessTranscriptWithAI;
use App\Jobs\SendCompletionNotification;
use App\Jobs\TranscribeAudio;
use App\Mail\LivestreamProcessingFailed;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Services\HistoricMedia\HistoricStagingGuard;
use App\Services\Media\Video\VideoStorageService;
use App\Services\Processing\MediaProcessingRunTransitionService;
use App\Services\Processing\ProcessingPipelineBuilder;
use App\Services\Processing\ProcessingRunFailureHandler;
use App\Services\Processing\ProcessingRunOrchestrator;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\SerializableClosure\SerializableClosure;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AlwaysFailingJob;
use Tests\TestCase;

class ProcessingRunOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_starts_audio_runs_via_the_canonical_orchestrator_entrypoint(): void
    {
        Bus::fake();

        $processingLog = MediaProcessingLog::factory()->audio()->pending()->create();

        $builder = $this->mock(ProcessingPipelineBuilder::class);
        $builder->shouldReceive('buildAudioPipeline')
            ->once()
            ->with($processingLog)
            ->andReturn([new CleanupTemporaryFiles($processingLog)]);

        $this->app->forgetInstance(ProcessingRunOrchestrator::class);

        app(ProcessingRunOrchestrator::class)->start($processingLog);

        Bus::assertDispatched(CleanupTemporaryFiles::class, function (CleanupTemporaryFiles $job): bool {
            return $job->queue === 'audio-processing';
        });
    }

    #[Test]
    public function it_routes_historic_chain_stages_to_their_calibrated_queues(): void
    {
        Queue::fake();
        config([
            'media-processing.historic_import.stages.ffmpeg.queue' => 'historic-ffmpeg-test',
            'media-processing.historic_import.stages.whisper.queue' => 'historic-whisper-test',
            'media-processing.historic_import.stages.llm.queue' => 'historic-llm-test',
        ]);

        $processingLog = MediaProcessingLog::factory()->video()->pending()->create([
            'processing_metadata' => [
                'historic_import' => [
                    'job_key' => hash('sha256', 'historic-queue-routing'),
                ],
            ],
        ]);

        $builder = $this->mock(ProcessingPipelineBuilder::class);
        $builder->shouldReceive('buildDirectVideoPipeline')
            ->once()
            ->with($processingLog)
            ->andReturn([
                new ExtractAudioFromVideo($processingLog),
                new TranscribeAudio($processingLog),
                new ProcessTranscriptWithAI($processingLog),
            ]);

        $this->assertSame(
            hash('sha256', 'historic-queue-routing'),
            $processingLog->processing_metadata?->toArray()['historic_import']['job_key'] ?? null,
        );

        $this->app->forgetInstance(ProcessingRunOrchestrator::class);

        app(ProcessingRunOrchestrator::class)->start($processingLog);

        $first = null;
        Queue::assertPushed(ExtractAudioFromVideo::class, function (ExtractAudioFromVideo $job) use (&$first): bool {
            $first = $job;

            return true;
        });

        $this->assertInstanceOf(ExtractAudioFromVideo::class, $first);
        $this->assertSame('historic-ffmpeg-test', $first->queue);
        $chain = array_map(static fn (string $job): object => unserialize($job), $first->chained);

        $this->assertSame('historic-whisper-test', $chain[0]->queue);
        $this->assertSame('historic-llm-test', $chain[1]->queue);
    }

    #[Test]
    public function it_retries_audio_from_the_failed_phase_using_registry_order(): void
    {
        Bus::fake();

        $processingLog = MediaProcessingLog::factory()->audio()->failed()->create([
            'current_step' => 'transcribing_audio_failed',
            'error_message' => 'Temporary outage',
        ]);

        $result = app(ProcessingRunOrchestrator::class)->retry($processingLog);

        $this->assertTrue($result->success);

        $processingLog->refresh();
        $this->assertSame(ProcessingStatus::Pending, $processingLog->status);
        $this->assertNull($processingLog->error_message);

        Bus::assertChained([
            TranscribeAudio::class,
            ProcessTranscriptWithAI::class,
            SendCompletionNotification::class,
            CleanupTemporaryFiles::class,
        ]);
    }

    #[Test]
    public function it_applies_livestream_failure_handling_when_resuming_after_manual_review(): void
    {
        Mail::fake();
        config(['queue.default' => 'sync']);

        $processingLog = MediaProcessingLog::factory()->livestream()->pending()->create([
            'source_file_path' => 'livestreams/source.mp4',
        ]);

        $builder = $this->mock(ProcessingPipelineBuilder::class);
        $builder->shouldReceive('buildLivestreamPostReviewChainJobs')
            ->once()
            ->andReturn([new AlwaysFailingJob]);

        $storageService = $this->mock(VideoStorageService::class);
        $storageService->shouldReceive('cleanupTemporaryFiles')
            ->once()
            ->with(Mockery::type('array'));

        $this->app->forgetInstance(ProcessingRunFailureHandler::class);
        $this->app->forgetInstance(ProcessingRunOrchestrator::class);

        try {
            app(ProcessingRunOrchestrator::class)->resumeAfterManualReview($processingLog);
        } catch (\RuntimeException) {
            // Sync queue rethrows after the chain catch callback has run.
        }

        $processingLog->refresh();
        $this->assertSame(ProcessingStatus::Failed, $processingLog->status);
        $this->assertNotNull($processingLog->completed_at);
        Mail::assertQueued(LivestreamProcessingFailed::class, fn (LivestreamProcessingFailed $mail): bool => $mail->processingId === $processingLog->processing_id);
    }

    #[Test]
    public function it_cancels_livestream_runs_and_cleans_up_temporary_files(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create([
            'source_file_path' => 'livestreams/source.mp4',
            'processing_metadata' => [
                'extracted_segment_path' => 'livestreams/segment.mp4',
                'extracted_audio_path' => 'livestreams/segment.mp3',
                'temp_video_path' => 'livestreams/temp.mp4',
            ],
        ]);

        $storageService = $this->mock(VideoStorageService::class);
        $storageService->shouldReceive('cleanupTemporaryFiles')
            ->once()
            ->with([
                'livestreams/source.mp4',
                'livestreams/segment.mp4',
                'livestreams/segment.mp3',
                'livestreams/temp.mp4',
            ]);

        $this->app->forgetInstance(ProcessingRunOrchestrator::class);

        $cancelled = app(ProcessingRunOrchestrator::class)->cancel($processingLog);

        $this->assertTrue($cancelled);
        $processingLog->refresh();
        $this->assertSame(ProcessingStatus::Cancelled, $processingLog->status);
    }

    #[Test]
    public function it_retries_livestream_runs_from_the_phase_cursor_instead_of_restarting_the_full_pipeline(): void
    {
        Bus::fake();

        $processingLog = MediaProcessingLog::factory()->livestream()->failed()->create([
            'current_step' => 'transcribing_audio_failed',
            'error_message' => 'Temporary outage',
        ]);

        $result = app(ProcessingRunOrchestrator::class)->retry($processingLog);

        $this->assertTrue($result->success);

        $processingLog->refresh();
        $this->assertSame(ProcessingStatus::Pending, $processingLog->status);
        $this->assertNull($processingLog->error_message);

        Bus::assertChained([
            CreateSermonTranscriptFromService::class,
            ProcessTranscriptWithAI::class,
            AssessSermonVideoQuality::class,
            GenerateThumbnail::class,
            PrepareSectionPublicationCandidates::class,
            SendCompletionNotification::class,
            CleanupTemporaryFiles::class,
        ]);
    }

    /**
     * The batch root lives in the staging disk's *root*, not in the artifact keys a run
     * records: {@see HistoricStagingGuard::activate()} appends
     * `historic-batches/<plan-hash>` to it. A retry dispatched without reactivating the run's
     * own context therefore resumes on a worker whose staging disk sits one directory above
     * the retained transcript, and DetectServiceStructure fails with "Full-service transcript
     * artifact missing" whatever the original defect was.
     */
    #[Test]
    public function it_retries_a_historic_run_with_its_recorded_staging_context_active(): void
    {
        Bus::fake();

        $stagingRoot = sys_get_temp_dir().'/orchestrator-staging-'.uniqid();
        mkdir($stagingRoot, 0755, true);
        config([
            'filesystems.disks.historic_staging.root' => $stagingRoot,
            'media-processing.storage.historic_staging_disk' => 'historic_staging',
            'media-processing.storage.sermon_disk' => 'historic_staging',
            'media-processing.storage.transcript_disk' => 'historic_staging',
        ]);
        Storage::forgetDisk('historic_staging');

        $stagingContext = app(HistoricStagingGuard::class)->contextForApprovedPlan(
            hash('sha256', 'manifest'),
            hash('sha256', 'plan'),
        );
        $transcriptKey = 'service-transcripts/2025-02-09/morning-retry.normalized.json';

        $processingLog = MediaProcessingLog::factory()->livestream()->failed()->create([
            'current_step' => 'detecting_service_structure_failed',
            'error_message' => 'Detected sections overlap.',
            'processing_metadata' => [
                'historic_import' => [
                    'job_key' => 'historic-job-key',
                    'staging_context' => $stagingContext->toArray(),
                ],
                'service_transcript_path' => $transcriptKey,
            ],
        ]);

        app(HistoricStagingContextRegistry::class)->within(
            $stagingContext,
            static fn () => Storage::disk('historic_staging')->put($transcriptKey, '{"cues":[]}'),
        );

        $transcriptReadable = null;
        // Partial: the phase registry also asks the real builder for the chain's job classes
        // when it resolves the retry cursor.
        $builder = $this->partialMock(ProcessingPipelineBuilder::class);
        $builder->shouldReceive('buildLivestreamChainJobs')
            ->andReturnUsing(function (MediaProcessingLog $log) use (&$transcriptReadable): array {
                $transcriptReadable = $log->hasStoredServiceTranscript();

                return [new CleanupTemporaryFiles($log)];
            });

        $this->app->forgetInstance(ProcessingRunOrchestrator::class);
        $result = app(ProcessingRunOrchestrator::class)->retry($processingLog);

        File::deleteDirectory($stagingRoot);

        $this->assertTrue($result->success, $result->message);
        $this->assertTrue(
            $transcriptReadable,
            'The retry ran outside the run\'s historic staging context, so its retained '
            .'full-service transcript was not readable at the key the run recorded.',
        );
    }

    /**
     * A historic run with no recorded staging context cannot resolve its own artifacts, so a
     * retry must refuse rather than silently resume against an un-rooted staging disk.
     */
    #[Test]
    public function it_refuses_to_retry_a_historic_run_that_records_no_staging_context(): void
    {
        Bus::fake();

        $processingLog = MediaProcessingLog::factory()->livestream()->failed()->create([
            'current_step' => 'detecting_service_structure_failed',
            'processing_metadata' => [
                'historic_import' => ['job_key' => 'historic-job-key'],
            ],
        ]);

        $result = app(ProcessingRunOrchestrator::class)->retry($processingLog);

        $this->assertFalse($result->success);
        $this->assertSame('HISTORIC_STAGING_CONTEXT_MISSING', $result->errorCode);
        Bus::assertNothingDispatched();
    }

    #[Test]
    public function it_starts_auto_trim_video_runs_via_the_canonical_orchestrator_entrypoint(): void
    {
        Bus::fake();

        $processingLog = MediaProcessingLog::factory()->video()->pending()->create([
            'processing_metadata' => [
                'video_processing_mode' => MediaProcessingLog::VIDEO_PROCESSING_MODE_AUTO_TRIM,
                'trim_requested' => true,
            ],
        ]);

        $builder = $this->mock(ProcessingPipelineBuilder::class);
        $builder->shouldReceive('buildAutoTrimVideoPipeline')
            ->once()
            ->with($processingLog)
            ->andReturn([new CleanupTemporaryFiles($processingLog)]);

        $this->app->forgetInstance(ProcessingRunOrchestrator::class);

        app(ProcessingRunOrchestrator::class)->start($processingLog);

        Bus::assertDispatched(CleanupTemporaryFiles::class, function (CleanupTemporaryFiles $job): bool {
            return $job->queue === 'video-processing';
        });
    }

    #[Test]
    public function it_resumes_auto_trim_video_runs_after_manual_review_using_the_post_review_chain(): void
    {
        Mail::fake();
        config(['queue.default' => 'sync']);

        $processingLog = MediaProcessingLog::factory()->video()->pending()->create([
            'source_file_path' => 'temp/video-processing/sermon.mp4',
            'processing_metadata' => [
                'video_processing_mode' => MediaProcessingLog::VIDEO_PROCESSING_MODE_AUTO_TRIM,
                'trim_requested' => true,
            ],
        ]);

        $builder = $this->mock(ProcessingPipelineBuilder::class);
        $builder->shouldReceive('buildAutoTrimVideoPostReviewChainJobs')
            ->once()
            ->andReturn([new AlwaysFailingJob]);

        $storageService = $this->mock(VideoStorageService::class);
        $storageService->shouldReceive('cleanupTemporaryFiles')
            ->once()
            ->with(Mockery::type('array'));

        $this->app->forgetInstance(ProcessingRunFailureHandler::class);
        $this->app->forgetInstance(ProcessingRunOrchestrator::class);

        try {
            app(ProcessingRunOrchestrator::class)->resumeAfterManualReview($processingLog);
        } catch (\RuntimeException) {
            // Sync queue rethrows after the chain catch callback has run.
        }

        $processingLog->refresh();
        $this->assertSame(ProcessingStatus::Failed, $processingLog->status);
        $this->assertStringContainsString('Video auto-trim processing failed', $processingLog->error_message);
    }

    #[Test]
    public function it_retries_auto_trim_video_runs_from_the_failed_phase(): void
    {
        Bus::fake();

        $processingLog = MediaProcessingLog::factory()->video()->failed()->create([
            'current_step' => 'transcribing_audio_failed',
            'error_message' => 'Temporary outage',
            'processing_metadata' => [
                'video_processing_mode' => MediaProcessingLog::VIDEO_PROCESSING_MODE_AUTO_TRIM,
                'trim_requested' => true,
            ],
        ]);

        $result = app(ProcessingRunOrchestrator::class)->retry($processingLog);

        $this->assertTrue($result->success);

        $processingLog->refresh();
        $this->assertSame(ProcessingStatus::Pending, $processingLog->status);
        $this->assertNull($processingLog->error_message);

        Bus::assertChained([
            CreateSermonTranscriptFromService::class,
            ProcessTranscriptWithAI::class,
            AssessSermonVideoQuality::class,
            GenerateThumbnail::class,
            SendCompletionNotification::class,
            CleanupTemporaryFiles::class,
        ]);
    }

    #[Test]
    public function it_cancels_auto_trim_video_runs_and_cleans_up_segmentation_files(): void
    {
        $processingLog = MediaProcessingLog::factory()->video()->processing()->create([
            'source_file_path' => 'temp/video-processing/sermon.mp4',
            'processing_metadata' => [
                'video_processing_mode' => MediaProcessingLog::VIDEO_PROCESSING_MODE_AUTO_TRIM,
                'trim_requested' => true,
                'extracted_segment_path' => 'temp/video-processing/segment.mp4',
                'extracted_audio_path' => 'temp/video-processing/segment.mp3',
            ],
        ]);

        $storageService = $this->mock(VideoStorageService::class);
        $storageService->shouldReceive('cleanupTemporaryFiles')
            ->once()
            ->with([
                'temp/video-processing/sermon.mp4',
                'temp/video-processing/segment.mp4',
                'temp/video-processing/segment.mp3',
            ]);

        $this->app->forgetInstance(ProcessingRunOrchestrator::class);

        $cancelled = app(ProcessingRunOrchestrator::class)->cancel($processingLog);

        $this->assertTrue($cancelled);
        $processingLog->refresh();
        $this->assertSame(ProcessingStatus::Cancelled, $processingLog->status);
    }

    #[Test]
    public function it_records_stable_main_chain_and_fan_out_identities_for_historic_runs(): void
    {
        Bus::fake();

        $jobKey = hash('sha256', 'historic-manifest-item');
        $processingLog = MediaProcessingLog::factory()->livestream()->pending()->create([
            'processing_id' => 'historic-queue-identities',
            'processing_metadata' => [
                'historic_import' => [
                    'job_key' => $jobKey,
                ],
            ],
        ]);

        app(ProcessingRunOrchestrator::class)->start($processingLog);

        $pendingBatch = null;
        Bus::assertBatched(function (PendingBatch $batch) use (&$pendingBatch): bool {
            $pendingBatch = $batch;

            return true;
        });

        $processingLog->refresh();
        $queue = $processingLog->processing_metadata?->toArray()['historic_import']['queue'] ?? [];

        $this->assertSame(hash('sha256', "historic-main-chain\0{$jobKey}"), $queue['main_chain_id']);
        $this->assertIsString($queue['fan_out_batch_id']);
        $this->assertArrayNotHasKey('main_chain_dispatched_at', $queue);

        $thenCallback = $this->unwrapCallback($pendingBatch->thenCallbacks()[0]);
        $thenCallback(Bus::dispatchFakeBatch('historic-queue-identities'));

        $processingLog->refresh();
        $queue = $processingLog->processing_metadata?->toArray()['historic_import']['queue'] ?? [];

        $this->assertNotEmpty($queue['main_chain_dispatched_at']);
    }

    #[Test]
    public function it_does_not_dispatch_the_livestream_follow_on_chain_after_cancellation(): void
    {
        Bus::fake();

        $processingLog = MediaProcessingLog::factory()->livestream()->pending()->create([
            'processing_id' => 'cancel-before-then-chain',
            'current_step' => 'rms_generation',
        ]);

        app(ProcessingRunOrchestrator::class)->start($processingLog);

        $pendingBatch = null;

        Bus::assertBatched(function (PendingBatch $batch) use (&$pendingBatch): bool {
            $pendingBatch = $batch;

            return true;
        });

        $this->assertInstanceOf(PendingBatch::class, $pendingBatch);

        app(MediaProcessingRunTransitionService::class)->markAsCancelled(
            $processingLog,
            'Processing cancelled by user'
        );

        $thenCallbacks = $pendingBatch->thenCallbacks();
        $this->assertCount(1, $thenCallbacks);

        $thenCallback = $this->unwrapCallback($thenCallbacks[0]);
        $thenCallback(Bus::dispatchFakeBatch('cancel-before-then-chain'));

        Bus::assertNotDispatched(CleanupTemporaryFiles::class);
    }

    private function unwrapCallback(mixed $callback): callable
    {
        if ($callback instanceof SerializableClosure) {
            return $callback->getClosure();
        }

        if (is_object($callback) && method_exists($callback, 'getClosure')) {
            return $callback->getClosure();
        }

        return $callback;
    }
}
