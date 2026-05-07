<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Contracts\TranscriptionServiceInterface;
use App\Exceptions\NonRetryableTranscriptionException;
use App\Exceptions\TranscriptionException;
use App\Jobs\TranscribeAudio;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\MediaProcessingRunTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TranscribeAudioTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_has_correct_retry_configuration(): void
    {
        $log = MediaProcessingLog::factory()->audio()->pending()->make();
        $job = new TranscribeAudio($log);

        $this->assertEquals(3, $job->tries);
        $this->assertEquals(1800, $job->timeout);
        $this->assertEquals([60, 300, 900], $job->backoff());
    }

    #[Test]
    public function it_transcribes_audio_file_and_updates_processing_log(): void
    {
        $sermon = Sermon::factory()->create();
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'source_file_path' => 'sermons/audio/test.mp3',
            'sermon_id' => $sermon->id,
            'processing_id' => 'test-proc-123',
        ]);

        $mockService = $this->createMock(TranscriptionServiceInterface::class);
        $mockService->expects($this->once())
            ->method('transcribe')
            ->with('sermons/audio/test.mp3', 'test-proc-123')
            ->willReturn('This is the sermon transcript content.');

        $mockService->expects($this->once())
            ->method('storeTranscript')
            ->with($sermon->id, 'This is the sermon transcript content.')
            ->willReturn('transcripts/'.$sermon->id.'/transcript.txt');

        Log::shouldReceive('info')->atLeast()->once();

        $job = new TranscribeAudio($log);
        $job->handle($mockService);

        $log->refresh();
        $this->assertEquals('transcripts/'.$sermon->id.'/transcript.txt', $log->transcript_file_path);

        $sermon->refresh();
        $this->assertEquals('transcripts/'.$sermon->id.'/transcript.txt', $sermon->transcript_file_path);
    }

    #[Test]
    public function it_does_not_cleanup_transcript_after_it_has_been_attached_to_the_sermon(): void
    {
        $sermon = Sermon::factory()->create();
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'source_file_path' => 'sermons/audio/test.mp3',
            'sermon_id' => $sermon->id,
            'processing_id' => 'test-proc-post-transcript-failure',
        ]);
        $transcriptPath = 'transcripts/'.$sermon->id.'/transcript.txt';

        $this->app->instance(
            MediaProcessingRunTransitionService::class,
            new class extends MediaProcessingRunTransitionService
            {
                public function updateStep(MediaProcessingLog $processingLog, string $step): bool
                {
                    if ($step === 'transcription_completed') {
                        throw new \RuntimeException('Step update failed after transcript storage');
                    }

                    return parent::updateStep($processingLog, $step);
                }
            }
        );

        $mockService = $this->createMock(TranscriptionServiceInterface::class);
        $mockService->expects($this->once())
            ->method('transcribe')
            ->with('sermons/audio/test.mp3', 'test-proc-post-transcript-failure')
            ->willReturn('This is the sermon transcript content.');
        $mockService->expects($this->once())
            ->method('storeTranscript')
            ->with($sermon->id, 'This is the sermon transcript content.')
            ->willReturn($transcriptPath);
        $mockService->expects($this->never())
            ->method('cleanupOnFailure');

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->atLeast()->once();

        $job = new TranscribeAudio($log);

        try {
            $job->handle($mockService);
            $this->fail('The post-transcript step update exception was not rethrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Step update failed after transcript storage', $e->getMessage());
        }

        $sermon->refresh();
        $this->assertSame($transcriptPath, $sermon->transcript_file_path);
    }

    #[Test]
    public function it_resolves_audio_path_from_source_file_path_for_audio_type(): void
    {
        $sermon = Sermon::factory()->create();
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'processing_type' => 'audio',
            'source_file_path' => 'sermons/audio/sermon.mp3',
            'sermon_id' => $sermon->id,
        ]);

        $mockService = $this->createMock(TranscriptionServiceInterface::class);
        $mockService->expects($this->once())
            ->method('transcribe')
            ->with('sermons/audio/sermon.mp3', $log->processing_id)
            ->willReturn('Transcript content.');
        $mockService->expects($this->once())
            ->method('storeTranscript')
            ->willReturn('transcripts/1/transcript.txt');

        Log::shouldReceive('info')->atLeast()->once();

        $job = new TranscribeAudio($log);
        $job->handle($mockService);
    }

    #[Test]
    public function it_resolves_audio_path_from_audio_file_path_for_video_type(): void
    {
        $sermon = Sermon::factory()->create();
        $log = MediaProcessingLog::factory()->video()->processing()->create([
            'processing_type' => 'video',
            'audio_file_path' => 'sermons/audio/extracted.mp3',
            'sermon_id' => $sermon->id,
        ]);

        $mockService = $this->createMock(TranscriptionServiceInterface::class);
        $mockService->expects($this->once())
            ->method('transcribe')
            ->with('sermons/audio/extracted.mp3', $log->processing_id)
            ->willReturn('Transcript content.');
        $mockService->expects($this->once())
            ->method('storeTranscript')
            ->willReturn('transcripts/1/transcript.txt');

        Log::shouldReceive('info')->atLeast()->once();

        $job = new TranscribeAudio($log);
        $job->handle($mockService);
    }

    #[Test]
    public function it_prefers_enhanced_audio_file_path_when_present(): void
    {
        $sermon = Sermon::factory()->create();
        $absoluteEnhancedPath = storage_path('app/temp/test-proc-absolute_enhanced.mp3');
        $log = MediaProcessingLog::factory()->video()->processing()->create([
            'processing_type' => 'video',
            'audio_file_path' => 'sermons/audio/extracted.mp3',
            'enhanced_audio_file_path' => $absoluteEnhancedPath,
            'sermon_id' => $sermon->id,
            'processing_id' => 'test-proc-absolute',
        ]);

        $mockService = $this->createMock(TranscriptionServiceInterface::class);
        $mockService->expects($this->once())
            ->method('transcribe')
            ->with($absoluteEnhancedPath, 'test-proc-absolute')
            ->willReturn('Transcript content.');
        $mockService->expects($this->once())
            ->method('storeTranscript')
            ->willReturn('transcripts/1/transcript.txt');

        Log::shouldReceive('info')->atLeast()->once();

        $job = new TranscribeAudio($log);
        $job->handle($mockService);
    }

    #[Test]
    public function it_throws_when_audio_path_is_empty(): void
    {
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'source_file_path' => null,
        ]);

        $mockService = $this->createMock(TranscriptionServiceInterface::class);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->atLeast()->once();

        $job = new TranscribeAudio($log);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No audio file path found');

        $job->handle($mockService);
    }

    #[Test]
    public function it_throws_when_transcription_returns_empty_content(): void
    {
        $sermon = Sermon::factory()->create();
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'source_file_path' => 'sermons/audio/test.mp3',
            'sermon_id' => $sermon->id,
        ]);

        $mockService = $this->createMock(TranscriptionServiceInterface::class);
        $mockService->expects($this->once())
            ->method('transcribe')
            ->with('sermons/audio/test.mp3', $log->processing_id)
            ->willReturn('');
        $mockService->expects($this->once())
            ->method('cleanupOnFailure');

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->atLeast()->once();

        $job = new TranscribeAudio($log);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Transcription returned empty content');

        $job->handle($mockService);
    }

    #[Test]
    public function it_marks_processing_log_as_failed_on_exception(): void
    {
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'source_file_path' => 'sermons/audio/test.mp3',
        ]);

        $mockService = $this->createMock(TranscriptionServiceInterface::class);
        $mockService->expects($this->once())
            ->method('transcribe')
            ->with('sermons/audio/test.mp3', $log->processing_id)
            ->willThrowException(new \Exception('Transcription API unavailable'));
        $mockService->expects($this->never())
            ->method('cleanupOnFailure'); // sermon_id is null

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->atLeast()->once();

        $job = new TranscribeAudio($log);

        try {
            $job->handle($mockService);
        } catch (\Exception $e) {
            $this->assertEquals('Transcription API unavailable', $e->getMessage());
        }

        $log->refresh();
        $this->assertEquals('failed', $log->status->value);
    }

    #[Test]
    public function failed_method_marks_processing_log_as_failed(): void
    {
        $sermon = Sermon::factory()->create();
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'sermon_id' => $sermon->id,
        ]);

        $mockService = $this->createMock(TranscriptionServiceInterface::class);
        $mockService->expects($this->once())
            ->method('cleanupOnFailure')
            ->with($sermon->id);

        $this->app->instance(TranscriptionServiceInterface::class, $mockService);

        Log::shouldReceive('error')->atLeast()->once();

        $job = new TranscribeAudio($log);
        $job->failed(new \Exception('Permanent failure'));

        $log->refresh();
        $this->assertEquals('failed', $log->status->value);
    }

    #[Test]
    public function failed_method_handles_cleanup_exception_gracefully(): void
    {
        $sermon = Sermon::factory()->create();
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'sermon_id' => $sermon->id,
        ]);

        $mockService = $this->createMock(TranscriptionServiceInterface::class);
        $mockService->expects($this->once())
            ->method('cleanupOnFailure')
            ->willThrowException(new \Exception('Cleanup failed'));

        $this->app->instance(TranscriptionServiceInterface::class, $mockService);

        Log::shouldReceive('error')->atLeast()->once();
        Log::shouldReceive('warning')->atLeast()->once();

        $job = new TranscribeAudio($log);
        $job->failed(new \Exception('Permanent failure'));

        $log->refresh();
        $this->assertEquals('failed', $log->status->value);
    }

    #[Test]
    public function it_fails_permanently_on_non_retryable_transcription_exception(): void
    {
        // A NonRetryableTranscriptionException (e.g., 401 invalid key, 413 oversized file)
        // must NOT be re-thrown — if it were, the job would be retried, burning all
        // $tries attempts against a deterministically-failing API call.
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

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->atLeast()->once();

        $job = new TranscribeAudio($log);

        // Must complete without throwing — no retry should be triggered.
        $job->handle($mockService);

        $log->refresh();
        $this->assertEquals('failed', $log->status->value);
    }

    #[Test]
    public function it_rethrows_retryable_transcription_exception_so_the_queue_can_retry(): void
    {
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'source_file_path' => 'sermons/audio/test.mp3',
        ]);

        $mockService = $this->createMock(TranscriptionServiceInterface::class);
        $mockService->expects($this->once())
            ->method('transcribe')
            ->willThrowException(new TranscriptionException('Rate limit exceeded'));
        $mockService->expects($this->never())
            ->method('cleanupOnFailure');

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->atLeast()->once();

        $job = new TranscribeAudio($log);

        $this->expectException(TranscriptionException::class);
        $this->expectExceptionMessage('Rate limit exceeded');

        $job->handle($mockService);
    }

    #[Test]
    public function it_skips_transcription_when_feature_flag_is_disabled(): void
    {
        config(['media-processing.transcription.enabled' => false]);

        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'source_file_path' => 'sermons/audio/test.mp3',
        ]);

        $mockService = $this->createMock(TranscriptionServiceInterface::class);
        $mockService->expects($this->never())->method('transcribe');

        Log::shouldReceive('info')->atLeast()->once();

        $job = new TranscribeAudio($log);
        $job->handle($mockService);

        $log->refresh();
        $this->assertEquals('transcription_skipped', $log->current_step);
    }

    #[Test]
    public function it_applies_without_overlapping_middleware_per_processing_id(): void
    {
        $log = MediaProcessingLog::factory()->audio()->pending()->make([
            'processing_id' => 'proc-lock-123',
        ]);

        $job = new TranscribeAudio($log);
        $middleware = $job->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
    }
}
