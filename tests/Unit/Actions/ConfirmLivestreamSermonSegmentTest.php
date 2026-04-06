<?php

namespace Tests\Unit\Actions;

use App\Actions\ConfirmLivestreamSermonSegment;
use App\Enums\ProcessingStatus;
use App\Jobs\ExtractSermon;
use App\Mail\LivestreamProcessingFailed;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\User;
use App\Services\MediaProcessingRunTransitionService;
use App\Services\ProcessingPipelineBuilder;
use App\Services\VideoStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AlwaysFailingJob;
use Tests\TestCase;

class ConfirmLivestreamSermonSegmentTest extends TestCase
{
    use RefreshDatabase;

    private ConfirmLivestreamSermonSegment $action;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        $this->action = app(ConfirmLivestreamSermonSegment::class);

        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    private function makeLivestreamLogAwaitingReview(?string $sourcePath = 'livestreams/2026/service.mp4'): MediaProcessingLog
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'source_file_path' => $sourcePath,
        ]);

        app(MediaProcessingRunTransitionService::class)->markForManualReview(
            $log,
            reasonCode: 'multiple_qualifying_speech_blocks',
            reasonMessage: 'Multiple speech blocks qualified.',
            speechSegments: [],
        );

        return $log->fresh();
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    #[Test]
    public function it_confirms_a_valid_speech_segment_and_dispatches_resume_chain(): void
    {
        Queue::fake();
        Storage::disk('local')->put('livestreams/2026/service.mp4', 'fake-video');

        $log = $this->makeLivestreamLogAwaitingReview('livestreams/2026/service.mp4');
        $segment = LivestreamSegment::factory()->speech()->forProcessingLog($log->id)->create();

        $this->action->execute($log->processing_id, $segment->id, $this->admin);

        $log->refresh();

        $this->assertSame(ProcessingStatus::Pending, $log->status);
        $this->assertSame('manual_review_confirmed', $log->current_step);
        $this->assertNull($log->error_message);
        $this->assertSame($segment->id, $log->manuallyConfirmedSegmentId());
        $this->assertSame($this->admin->id, $log->manualReviewMetadata()['confirmed_by_user_id']);

        Queue::assertPushed(ExtractSermon::class, function (ExtractSermon $job): bool {
            return $job->queue === config('media-processing.queues.livestream', 'livestream-processing');
        });
    }

    // -------------------------------------------------------------------------
    // Validation failures
    // -------------------------------------------------------------------------

    #[Test]
    public function it_rejects_an_unknown_processing_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Processing log not found.');

        $this->action->execute('00000000-0000-0000-0000-000000000000', 1, $this->admin);
    }

    #[Test]
    public function it_rejects_a_non_livestream_run(): void
    {
        Storage::disk('public')->put('audio/sermon.mp3', 'fake-audio');

        $log = MediaProcessingLog::factory()->audio()->create([
            'source_file_path' => 'audio/sermon.mp3',
        ]);
        app(MediaProcessingRunTransitionService::class)->markForManualReview($log, 'some_reason', 'Some reason.', []);
        $log = $log->fresh();

        $segment = LivestreamSegment::factory()->speech()->forProcessingLog($log->id)->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only segmentation-style runs');

        $this->action->execute($log->processing_id, $segment->id, $this->admin);
    }

    #[Test]
    public function it_rejects_a_run_not_awaiting_review(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->completed()->create();
        $segment = LivestreamSegment::factory()->speech()->forProcessingLog($log->id)->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not currently awaiting manual sermon review');

        $this->action->execute($log->processing_id, $segment->id, $this->admin);
    }

    #[Test]
    public function it_rejects_a_segment_from_another_processing_run(): void
    {
        Storage::disk('local')->put('livestreams/2026/service.mp4', 'fake-video');

        $log = $this->makeLivestreamLogAwaitingReview('livestreams/2026/service.mp4');

        $otherLog = MediaProcessingLog::factory()->livestream()->create();
        $foreignSegment = LivestreamSegment::factory()->speech()->forProcessingLog($otherLog->id)->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Segment not found on this processing run.');

        $this->action->execute($log->processing_id, $foreignSegment->id, $this->admin);
    }

    #[Test]
    public function it_rejects_a_non_speech_segment(): void
    {
        Storage::disk('local')->put('livestreams/2026/service.mp4', 'fake-video');

        $log = $this->makeLivestreamLogAwaitingReview('livestreams/2026/service.mp4');
        $segment = LivestreamSegment::factory()->song()->forProcessingLog($log->id)->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only speech segments');

        $this->action->execute($log->processing_id, $segment->id, $this->admin);
    }

    #[Test]
    public function it_rejects_when_source_video_is_missing(): void
    {
        $log = $this->makeLivestreamLogAwaitingReview('livestreams/2026/missing.mp4');
        $segment = LivestreamSegment::factory()->speech()->forProcessingLog($log->id)->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('source video file is no longer available');

        $this->action->execute($log->processing_id, $segment->id, $this->admin);
    }

    #[Test]
    public function it_rejects_when_source_file_path_is_null(): void
    {
        $log = $this->makeLivestreamLogAwaitingReview(null);
        $segment = LivestreamSegment::factory()->speech()->forProcessingLog($log->id)->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No source video path recorded');

        $this->action->execute($log->processing_id, $segment->id, $this->admin);
    }

    // -------------------------------------------------------------------------
    // Idempotency
    // -------------------------------------------------------------------------

    #[Test]
    public function it_rejects_a_second_confirmation_attempt(): void
    {
        Queue::fake();
        Storage::disk('local')->put('livestreams/2026/service.mp4', 'fake-video');

        $log = $this->makeLivestreamLogAwaitingReview('livestreams/2026/service.mp4');
        $segment = LivestreamSegment::factory()->speech()->forProcessingLog($log->id)->create();

        // First confirmation succeeds
        $this->action->execute($log->processing_id, $segment->id, $this->admin);

        // Second attempt fails because status is now pending (not required)
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not currently awaiting manual sermon review');

        $this->action->execute($log->processing_id, $segment->id, $this->admin);
    }

    // -------------------------------------------------------------------------
    // Failure parity
    // -------------------------------------------------------------------------

    #[Test]
    public function it_marks_the_run_as_failed_and_sends_notification_when_post_review_chain_fails(): void
    {
        Mail::fake();
        config(['queue.default' => 'sync']);
        Storage::disk('local')->put('livestreams/2026/service.mp4', 'fake-video');

        $log = $this->makeLivestreamLogAwaitingReview('livestreams/2026/service.mp4');
        $segment = LivestreamSegment::factory()->speech()->forProcessingLog($log->id)->create();

        $builder = $this->mock(ProcessingPipelineBuilder::class);
        $builder->shouldReceive('buildLivestreamPostReviewChainJobs')->andReturn([new AlwaysFailingJob]);
        $this->app->forgetInstance(\App\Services\ProcessingRunOrchestrator::class);

        $action = new ConfirmLivestreamSermonSegment(
            app(MediaProcessingRunTransitionService::class),
            app(VideoStorageService::class),
        );

        try {
            $action->execute($log->processing_id, $segment->id, $this->admin);
        } catch (\RuntimeException) {
            // Sync queue re-throws after firing the catch callback — expected.
        }

        $log->refresh();
        $this->assertSame(ProcessingStatus::Failed, $log->status);
        $this->assertNotNull($log->error_message);
        $this->assertNotNull($log->completed_at);
        Mail::assertQueued(LivestreamProcessingFailed::class, fn ($mail) => $mail->processingId === $log->processing_id);
    }

    #[Test]
    public function it_allows_confirmation_for_legacy_manual_review_rows_without_structured_metadata(): void
    {
        Queue::fake();
        Storage::disk('local')->put('livestreams/2026/legacy-service.mp4', 'fake-video');

        $log = MediaProcessingLog::factory()->livestream()->create([
            'source_file_path' => 'livestreams/2026/legacy-service.mp4',
            'status' => ProcessingStatus::Failed,
            'current_step' => 'manual_review_required',
            'error_message' => 'Manual Review Note: Multiple speech blocks met the 20-minute sermon threshold.',
            'processing_metadata' => null,
        ]);
        $segment = LivestreamSegment::factory()->speech()->forProcessingLog($log->id)->create();

        $this->action->execute($log->processing_id, $segment->id, $this->admin);

        $log->refresh();

        $this->assertSame('confirmed', $log->manualReviewMetadata()['status']);
        $this->assertSame('multiple_qualifying_speech_blocks', $log->manualReviewMetadata()['reason_code']);
        $this->assertSame($segment->id, $log->manuallyConfirmedSegmentId());
    }
}
