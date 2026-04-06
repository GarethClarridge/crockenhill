<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Services\MediaProcessingRunTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MediaProcessingRunTransitionServiceTest extends TestCase
{
    use RefreshDatabase;

    private MediaProcessingRunTransitionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(MediaProcessingRunTransitionService::class);
    }

    #[Test]
    public function it_transitions_a_run_through_processing_completion_and_failure_states(): void
    {
        $log = MediaProcessingLog::factory()->pending()->create();

        $this->assertTrue($this->service->markAsProcessing($log, 'step_one'));
        $this->assertSame(ProcessingStatus::PROCESSING, $log->status);
        $this->assertSame('step_one', $log->current_step);
        $this->assertNotNull($log->started_at);

        $this->assertTrue($this->service->markAsCompleted($log, 'completed'));
        $this->assertSame(ProcessingStatus::COMPLETED, $log->status);
        $this->assertSame('completed', $log->current_step);
        $this->assertNotNull($log->completed_at);

        $this->assertTrue($this->service->markAsFailed($log, 'Something went wrong', 'step_two'));
        $this->assertSame(ProcessingStatus::FAILED, $log->status);
        $this->assertSame('step_two', $log->current_step);
        $this->assertSame('Something went wrong', $log->error_message);
    }

    #[Test]
    public function it_preserves_completion_context_when_marking_a_run_completed(): void
    {
        $log = MediaProcessingLog::factory()->processing()->create([
            'current_step' => 'notification_failed',
            'error_message' => 'Notification failed: SMTP transport unavailable',
        ]);

        $this->service->markAsCompleted(
            $log,
            step: 'notification_failed',
            errorMessage: 'Notification failed: SMTP transport unavailable'
        );

        $this->assertSame(ProcessingStatus::COMPLETED, $log->status);
        $this->assertSame('notification_failed', $log->current_step);
        $this->assertSame('Notification failed: SMTP transport unavailable', $log->error_message);
    }

    #[Test]
    public function it_does_not_overwrite_a_cancelled_run_when_applying_terminal_transitions(): void
    {
        $log = MediaProcessingLog::factory()->cancelled()->create();

        $this->assertFalse($this->service->markAsProcessing($log, 'ignored'));
        $this->assertFalse($this->service->markAsCompleted($log, 'completed'));
        $this->assertFalse($this->service->markAsFailed($log, 'Should be ignored'));

        $log->refresh();
        $this->assertSame(ProcessingStatus::CANCELLED, $log->status);
        $this->assertSame('cancelled', $log->current_step);
    }

    #[Test]
    public function it_marks_a_run_for_manual_review_with_structured_metadata(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->processing()->create();

        $this->service->markForManualReview(
            $log,
            'ratio_below_threshold',
            'The longest speech block was not at least 1.5x longer.',
            [['segment_id' => 1, 'start_time' => 0.0, 'end_time' => 1320.0, 'duration' => 1320.0]]
        );

        $log->refresh();

        $this->assertSame(ProcessingStatus::FAILED, $log->status);
        $this->assertSame('manual_review_required', $log->current_step);
        $this->assertSame('ratio_below_threshold', $log->manualReviewMetadata()['reason_code']);
    }

    #[Test]
    public function it_confirms_a_manual_reviewed_sermon_segment(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();

        $this->service->markForManualReview($log, 'no_qualifying_speech_block', 'Needs review.');
        $log->refresh();

        $this->assertTrue($this->service->confirmSermonSegment($log, 42, 7));

        $log->refresh();

        $this->assertSame(ProcessingStatus::PENDING, $log->status);
        $this->assertSame('manual_review_confirmed', $log->current_step);
        $this->assertSame(42, $log->manuallyConfirmedSegmentId());
        $this->assertSame(7, $log->manualReviewMetadata()['confirmed_by_user_id']);
    }

    #[Test]
    public function it_resets_a_run_for_retry(): void
    {
        $log = MediaProcessingLog::factory()->failed()->create([
            'current_step' => 'transcribing_audio_failed',
            'error_message' => 'API timeout',
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);

        $this->assertTrue($this->service->resetForRetry($log));

        $log->refresh();

        $this->assertSame(ProcessingStatus::PENDING, $log->status);
        $this->assertSame('transcribing_audio_failed', $log->current_step);
        $this->assertNull($log->error_message);
        $this->assertNull($log->started_at);
        $this->assertNull($log->completed_at);
    }
}
