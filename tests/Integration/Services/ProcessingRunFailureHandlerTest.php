<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Contracts\ProvidesSafeMessage;
use App\Enums\ProcessingStatus;
use App\Mail\LivestreamProcessingFailed;
use App\Models\MediaProcessingLog;
use App\Services\Media\Video\VideoStorageService;
use App\Services\Processing\MediaProcessingRunTransitionService;
use App\Services\Processing\ProcessingNotificationRouter;
use App\Services\Processing\ProcessingRunFailureHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessingRunFailureHandlerTest extends TestCase
{
    use RefreshDatabase;

    private ProcessingRunFailureHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = app(ProcessingRunFailureHandler::class);
    }

    // ── Audio failures ────────────────────────────────────────────────────────

    #[Test]
    public function it_marks_an_audio_run_as_failed_with_a_safe_default_message(): void
    {
        $log = MediaProcessingLog::factory()->audio()->processing()->create();

        $this->handler->handle($log->processing_id, new \RuntimeException('Internal details'), ProcessingRunFailureHandler::PROFILE_AUDIO);

        $log->refresh();
        $this->assertSame(ProcessingStatus::Failed, $log->status);
        $this->assertStringContainsString('An internal error occurred during audio processing', $log->error_message);
    }

    #[Test]
    public function it_uses_the_safe_message_from_an_exception_implementing_the_contract(): void
    {
        $log = MediaProcessingLog::factory()->audio()->processing()->create();

        $exception = new class('raw message') extends \RuntimeException implements ProvidesSafeMessage
        {
            public function getSafeMessage(): string
            {
                return 'File format not supported.';
            }
        };

        $this->handler->handle($log->processing_id, $exception, ProcessingRunFailureHandler::PROFILE_AUDIO);

        $log->refresh();
        $this->assertStringContainsString('File format not supported.', $log->error_message);
    }

    // ── Video failures ────────────────────────────────────────────────────────

    #[Test]
    public function it_marks_a_video_run_as_failed(): void
    {
        $log = MediaProcessingLog::factory()->video()->processing()->create();

        $this->handler->handle($log->processing_id, new \RuntimeException('boom'), ProcessingRunFailureHandler::PROFILE_VIDEO);

        $log->refresh();
        $this->assertSame(ProcessingStatus::Failed, $log->status);
        $this->assertStringContainsString('An internal error occurred during video processing', $log->error_message);
    }

    // ── Livestream failures ───────────────────────────────────────────────────

    #[Test]
    public function it_marks_a_livestream_run_as_failed_and_queues_an_email(): void
    {
        Mail::fake();
        config(['media-processing.email.admin_email' => 'admin@example.com']);

        $storageService = $this->mock(VideoStorageService::class);
        $storageService->shouldNotReceive('cleanupTemporaryFiles');

        $transitionService = $this->mock(MediaProcessingRunTransitionService::class);
        $transitionService->shouldReceive('markAsFailed')->once();

        $this->app->forgetInstance(ProcessingRunFailureHandler::class);

        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'source_file_path' => null,
        ]);

        app(ProcessingRunFailureHandler::class)->handle(
            $log->processing_id,
            new \RuntimeException('Segmentation error'),
            ProcessingRunFailureHandler::PROFILE_LIVESTREAM
        );

        Mail::assertQueued(LivestreamProcessingFailed::class, fn (LivestreamProcessingFailed $mail): bool => $mail->processingId === $log->processing_id);
    }

    #[Test]
    public function it_extracts_temp_file_paths_from_metadata_for_cleanup(): void
    {
        Mail::fake();
        config(['media-processing.email.admin_email' => 'admin@example.com']);

        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
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

        $transitionService = $this->mock(MediaProcessingRunTransitionService::class);
        $transitionService->shouldReceive('markAsFailed')->once();

        $this->app->forgetInstance(ProcessingRunFailureHandler::class);

        app(ProcessingRunFailureHandler::class)->handle(
            $log->processing_id,
            new \RuntimeException('boom'),
            ProcessingRunFailureHandler::PROFILE_LIVESTREAM
        );
    }

    #[Test]
    public function it_retains_historic_livestream_inputs_after_terminal_failure_for_phase_retry(): void
    {
        Mail::fake();
        $jobKey = hash('sha256', 'historic-failed-job');
        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'source_file_path' => 'livestreams/source.mp4',
            'dedup_key' => $jobKey,
            'processing_metadata' => [
                'historic_import' => [
                    'job_key' => $jobKey,
                ],
                'extracted_segment_path' => 'livestreams/segment.mp4',
                'extracted_audio_path' => 'livestreams/segment.mp3',
            ],
        ]);

        $storageService = $this->mock(VideoStorageService::class);
        $storageService->shouldNotReceive('cleanupTemporaryFiles');
        $notificationRouter = $this->mock(ProcessingNotificationRouter::class);
        $notificationRouter->shouldReceive('suppressIfHistoric')->once()->andReturnTrue();

        $this->app->forgetInstance(ProcessingRunFailureHandler::class);

        app(ProcessingRunFailureHandler::class)->handle(
            $log->processing_id,
            new \RuntimeException('boom'),
            ProcessingRunFailureHandler::PROFILE_LIVESTREAM,
        );

        $log->refresh();

        $this->assertSame(ProcessingStatus::Failed, $log->status);
        $this->assertSame($jobKey, $log->dedup_key);
    }

    // ── Guard conditions ──────────────────────────────────────────────────────

    #[Test]
    public function it_does_nothing_when_the_processing_id_does_not_exist(): void
    {
        Mail::fake();

        $this->handler->handle('non-existent-id', new \RuntimeException('boom'), ProcessingRunFailureHandler::PROFILE_AUDIO);

        Mail::assertNothingQueued();
    }

    #[Test]
    public function it_skips_failure_handling_for_a_cancelled_run(): void
    {
        $log = MediaProcessingLog::factory()->audio()->cancelled()->create();

        $storageService = $this->mock(VideoStorageService::class);
        $storageService->shouldNotReceive('cleanupTemporaryFiles');

        $this->app->forgetInstance(ProcessingRunFailureHandler::class);

        app(ProcessingRunFailureHandler::class)->handle(
            $log->processing_id,
            new \RuntimeException('too late'),
            ProcessingRunFailureHandler::PROFILE_AUDIO
        );

        $log->refresh();
        $this->assertSame(ProcessingStatus::Cancelled, $log->status);
    }
}
