<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\ProcessingStatus;
use App\Jobs\CleanupTemporaryFiles;
use App\Jobs\ProcessTranscriptWithAI;
use App\Jobs\SendCompletionNotification;
use App\Jobs\TranscribeAudio;
use App\Mail\LivestreamProcessingFailed;
use App\Models\MediaProcessingLog;
use App\Services\ProcessingPipelineBuilder;
use App\Services\ProcessingRunOrchestrator;
use App\Services\VideoStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AlwaysFailingJob;
use Tests\TestCase;

class ProcessingRunOrchestratorTest extends TestCase
{
    use RefreshDatabase;

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
        $this->assertSame(ProcessingStatus::PENDING, $processingLog->status);
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

        $this->app->forgetInstance(\App\Services\ProcessingRunFailureHandler::class);
        $this->app->forgetInstance(ProcessingRunOrchestrator::class);

        try {
            app(ProcessingRunOrchestrator::class)->resumeAfterManualReview($processingLog);
        } catch (\RuntimeException) {
            // Sync queue rethrows after the chain catch callback has run.
        }

        $processingLog->refresh();
        $this->assertSame(ProcessingStatus::FAILED, $processingLog->status);
        $this->assertNotNull($processingLog->completed_at);
        Mail::assertQueued(LivestreamProcessingFailed::class, fn (LivestreamProcessingFailed $mail): bool => $mail->processingId === $processingLog->processing_id);
    }

    #[Test]
    public function it_applies_livestream_failure_handling_when_reclassifying(): void
    {
        Mail::fake();
        config(['queue.default' => 'sync']);

        $processingLog = MediaProcessingLog::factory()->livestream()->completed()->create([
            'source_file_path' => 'livestreams/reclassify.mp4',
        ]);

        $builder = $this->mock(ProcessingPipelineBuilder::class);
        $builder->shouldReceive('buildSectionReclassificationChainJobs')
            ->once()
            ->andReturn([new AlwaysFailingJob]);

        $storageService = $this->mock(VideoStorageService::class);
        $storageService->shouldReceive('cleanupTemporaryFiles')
            ->once()
            ->with(Mockery::type('array'));

        $this->app->forgetInstance(\App\Services\ProcessingRunFailureHandler::class);
        $this->app->forgetInstance(ProcessingRunOrchestrator::class);

        try {
            app(ProcessingRunOrchestrator::class)->reclassify($processingLog);
        } catch (\RuntimeException) {
            // Sync queue rethrows after the chain catch callback has run.
        }

        $processingLog->refresh();
        $this->assertSame(ProcessingStatus::FAILED, $processingLog->status);
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
        $this->assertSame(ProcessingStatus::CANCELLED, $processingLog->status);
    }
}
