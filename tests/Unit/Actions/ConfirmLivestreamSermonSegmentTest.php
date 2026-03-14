<?php

namespace Tests\Unit\Actions;

use App\Actions\ConfirmLivestreamSermonSegment;
use App\Enums\ProcessingStatus;
use App\Jobs\ExtractSermon;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\User;
use App\Services\ProcessingPipelineBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConfirmLivestreamSermonSegmentTest extends TestCase
{
    use RefreshDatabase;

    private ConfirmLivestreamSermonSegment $action;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->action = new ConfirmLivestreamSermonSegment(new ProcessingPipelineBuilder);

        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    private function makeLivestreamLogAwaitingReview(?string $sourcePath = 'livestreams/2026/service.mp4'): MediaProcessingLog
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'source_file_path' => $sourcePath,
        ]);

        $log->markForManualReview(
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
        Bus::fake();
        Storage::fake('public');
        Storage::disk('public')->put('livestreams/2026/service.mp4', 'fake-video');

        $log = $this->makeLivestreamLogAwaitingReview('livestreams/2026/service.mp4');
        $segment = LivestreamSegment::factory()->speech()->forProcessingLog($log->id)->create();

        $this->action->execute($log->processing_id, $segment->id, $this->admin);

        $log->refresh();

        $this->assertSame(ProcessingStatus::PENDING, $log->status);
        $this->assertSame('manual_review_confirmed', $log->current_step);
        $this->assertNull($log->error_message);
        $this->assertSame($segment->id, $log->manuallyConfirmedSegmentId());
        $this->assertSame($this->admin->id, $log->manualReviewMetadata()['confirmed_by_user_id']);

        Bus::assertDispatched(ExtractSermon::class);
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
        $log->markForManualReview('some_reason', 'Some reason.', []);
        $log = $log->fresh();

        $segment = LivestreamSegment::factory()->speech()->forProcessingLog($log->id)->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only livestream runs');

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
        Storage::disk('public')->put('livestreams/2026/service.mp4', 'fake-video');

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
        Storage::disk('public')->put('livestreams/2026/service.mp4', 'fake-video');

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
        Bus::fake();
        Storage::disk('public')->put('livestreams/2026/service.mp4', 'fake-video');

        $log = $this->makeLivestreamLogAwaitingReview('livestreams/2026/service.mp4');
        $segment = LivestreamSegment::factory()->speech()->forProcessingLog($log->id)->create();

        // First confirmation succeeds
        $this->action->execute($log->processing_id, $segment->id, $this->admin);

        // Second attempt fails because status is now pending (not required)
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not currently awaiting manual sermon review');

        $this->action->execute($log->processing_id, $segment->id, $this->admin);
    }
}
