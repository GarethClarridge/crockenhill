<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Contracts\TranscriptionServiceInterface;
use App\Exceptions\NonRetryableTranscriptionException;
use App\Exceptions\TranscriptionException;
use App\Jobs\TranscribeAudio;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TD-005B: Verify that retry ownership lives exclusively at the job layer.
 *
 * AudioTranscriptionService must be a single-attempt boundary: classify the
 * failure and throw a typed exception. All retry timing (backoff, re-queue)
 * is owned by TranscribeAudio via Laravel's queue infrastructure — no
 * blocking sleep() in the service.
 */
class TranscriptionRetryOwnershipTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function job_has_three_tries_and_exponential_backoff(): void
    {
        // Job owns all retry timing; service never sleeps.
        // Backoff: 1 min → 5 min → 15 min — covers transient 429 / network windows.
        $log = MediaProcessingLog::factory()->audio()->pending()->make();
        $job = new TranscribeAudio($log);

        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 300, 900], $job->backoff());
    }

    #[Test]
    public function job_uses_without_overlapping_middleware_to_prevent_concurrent_retries(): void
    {
        config(['media-processing.transcription.service' => 'mock']);

        $log = MediaProcessingLog::factory()->audio()->pending()->make([
            'processing_id' => 'proc-abc',
        ]);
        $job = new TranscribeAudio($log);
        $middleware = $job->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
    }

    #[Test]
    public function job_rethrows_retryable_transcription_exception_for_queue_to_retry(): void
    {
        // When the service throws TranscriptionException (transient: 429, network),
        // the job must re-throw so the queue retries with its configured backoff.
        // This confirms the job does NOT swallow retryable failures.
        // Note: no sermon_id so cleanupOnFailure is not called (guarded by sermon_id check).
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'source_file_path' => 'sermons/audio/test.mp3',
            'sermon_id' => null,
        ]);

        $mockService = $this->createMock(TranscriptionServiceInterface::class);
        $mockService->expects($this->once())
            ->method('transcribe')
            ->willThrowException(new TranscriptionException('Rate limit exceeded'));

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('error')->atLeast()->once();

        $job = new TranscribeAudio($log);

        $this->expectException(TranscriptionException::class);
        $this->expectExceptionMessage('Rate limit exceeded');

        $job->handle($mockService);
    }

    #[Test]
    public function job_does_not_rethrow_non_retryable_exception(): void
    {
        // NonRetryableTranscriptionException (e.g., 401 bad key, 413 oversized file)
        // must NOT be re-thrown — if it were, the queue would burn all remaining
        // tries against a deterministically-failing condition.
        $sermon = Sermon::factory()->create();
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'source_file_path' => 'sermons/audio/test.mp3',
            'sermon_id' => $sermon->id,
        ]);

        $mockService = $this->createMock(TranscriptionServiceInterface::class);
        $mockService->expects($this->once())
            ->method('transcribe')
            ->willThrowException(new NonRetryableTranscriptionException('Invalid API key'));
        $mockService->expects($this->once())
            ->method('cleanupOnFailure')
            ->with($sermon->id);

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('error')->atLeast()->once();

        $job = new TranscribeAudio($log);

        // Must complete without throwing — no queue retry will be triggered.
        $job->handle($mockService);

        $log->refresh();
        $this->assertSame('failed', $log->status->value);
    }

    #[Test]
    public function job_marks_processing_log_failed_on_non_retryable_error(): void
    {
        $sermon = Sermon::factory()->create();
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'source_file_path' => 'sermons/audio/test.mp3',
            'sermon_id' => $sermon->id,
        ]);

        $mockService = $this->createMock(TranscriptionServiceInterface::class);
        $mockService->method('transcribe')
            ->willThrowException(new NonRetryableTranscriptionException('Payload too large'));
        $mockService->method('cleanupOnFailure');

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('error')->atLeast()->once();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $job = new TranscribeAudio($log);
        $job->handle($mockService);

        $log->refresh();
        $this->assertSame('failed', $log->status->value);
    }

    #[Test]
    public function job_marks_processing_log_failed_on_retryable_error(): void
    {
        // The job marks the log as failed on each attempt so failed state is always
        // persisted. The queue will retry and the job will update the step back to
        // processing on the next attempt. This ensures no attempt is silently lost.
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'source_file_path' => 'sermons/audio/test.mp3',
        ]);

        $mockService = $this->createMock(TranscriptionServiceInterface::class);
        $mockService->method('transcribe')
            ->willThrowException(new TranscriptionException('Rate limit'));

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('error')->atLeast()->once();

        $job = new TranscribeAudio($log);

        try {
            $job->handle($mockService);
        } catch (TranscriptionException) {
            // Expected — the re-throw is what we want
        }

        $log->refresh();
        $this->assertSame('failed', $log->status->value);
    }

    #[Test]
    public function non_retryable_transcription_exception_is_a_transcription_exception(): void
    {
        // NonRetryableTranscriptionException must extend TranscriptionException so
        // callers only need to catch the base type unless they need to distinguish.
        $e = new NonRetryableTranscriptionException('Bad key');

        $this->assertInstanceOf(TranscriptionException::class, $e);
    }

    #[Test]
    public function transcription_exception_is_not_non_retryable(): void
    {
        // Conversely, a plain TranscriptionException must NOT be a
        // NonRetryableTranscriptionException — they are distinct classes.
        $e = new TranscriptionException('Network timeout');

        $this->assertNotInstanceOf(NonRetryableTranscriptionException::class, $e);
    }
}
