<?php

namespace Tests\Unit\Services;

use App\Services\ProcessingExceptionHandler;
use App\Services\ProcessingResult;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessingExceptionHandlerTest extends TestCase
{
    private ProcessingExceptionHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new ProcessingExceptionHandler;
    }

    // --- handleVideoProcessingException() ---

    #[Test]
    public function it_returns_failure_result_for_video_processing_exception(): void
    {
        Log::shouldReceive('error')->once();

        $exception = new \RuntimeException('FFmpeg crashed');
        $context = ['processing_id' => 'proc-123'];

        $result = $this->handler->handleVideoProcessingException($exception, $context);

        $this->assertInstanceOf(ProcessingResult::class, $result);
        $this->assertFalse($result->success);
        $this->assertEquals('proc-123', $result->processingId);
    }

    #[Test]
    public function it_logs_video_processing_error_with_context(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $message === 'Video processing failed'
                    && $context['processing_id'] === 'proc-123'
                    && $context['error'] === 'FFmpeg crashed';
            });

        $exception = new \RuntimeException('FFmpeg crashed');
        $this->handler->handleVideoProcessingException($exception, ['processing_id' => 'proc-123']);
    }

    // --- handleAudioProcessingException() ---

    #[Test]
    public function it_returns_failure_result_for_audio_processing_exception(): void
    {
        Log::shouldReceive('error')->once();

        $exception = new \RuntimeException('Transcription failed');
        $context = ['processing_id' => 'proc-456'];

        $result = $this->handler->handleAudioProcessingException($exception, $context);

        $this->assertFalse($result->success);
        $this->assertEquals('proc-456', $result->processingId);
    }

    // --- handleLivestreamProcessingException() ---

    #[Test]
    public function it_returns_failure_result_for_livestream_processing_exception(): void
    {
        Log::shouldReceive('error')->once();

        $exception = new \RuntimeException('Segmentation error');
        $context = ['processing_id' => 'proc-789'];

        $result = $this->handler->handleLivestreamProcessingException($exception, $context);

        $this->assertFalse($result->success);
        $this->assertEquals('proc-789', $result->processingId);
    }

    // --- User-friendly messages ---

    #[Test]
    public function it_maps_invalid_argument_exception_to_friendly_message(): void
    {
        Log::shouldReceive('error')->once();

        $exception = new \InvalidArgumentException('bad input');
        $result = $this->handler->handleVideoProcessingException($exception, ['processing_id' => 'p1']);

        $this->assertEquals(
            'The uploaded file does not meet the required format or size specifications.',
            $result->message
        );
    }

    #[Test]
    public function it_maps_disk_space_error_to_friendly_message(): void
    {
        Log::shouldReceive('error')->once();

        $exception = new \RuntimeException('No disk space available');
        $result = $this->handler->handleAudioProcessingException($exception, ['processing_id' => 'p2']);

        $this->assertEquals(
            'Insufficient storage space to process the file. Please contact support.',
            $result->message
        );
    }

    #[Test]
    public function it_maps_permission_error_to_friendly_message(): void
    {
        Log::shouldReceive('error')->once();

        $exception = new \RuntimeException('File permission denied');
        $result = $this->handler->handleVideoProcessingException($exception, ['processing_id' => 'p3']);

        $this->assertEquals(
            'File access permission error. Please contact support.',
            $result->message
        );
    }

    #[Test]
    public function it_maps_timeout_error_to_friendly_message(): void
    {
        Log::shouldReceive('error')->once();

        $exception = new \RuntimeException('Process timeout after 300s');
        $result = $this->handler->handleVideoProcessingException($exception, ['processing_id' => 'p4']);

        $this->assertEquals(
            'Processing timed out. Please try again with a smaller file or contact support.',
            $result->message
        );
    }

    #[Test]
    public function it_returns_default_message_for_unknown_exception(): void
    {
        Log::shouldReceive('error')->once();

        $exception = new \RuntimeException('Something completely unexpected');
        $result = $this->handler->handleVideoProcessingException($exception, ['processing_id' => 'p5']);

        $this->assertStringContainsString('unexpected error', $result->message);
    }

    // --- Error codes ---

    #[Test]
    public function it_maps_invalid_argument_to_invalid_file_format_code(): void
    {
        Log::shouldReceive('error')->once();

        $exception = new \InvalidArgumentException('bad');
        $result = $this->handler->handleVideoProcessingException($exception, ['processing_id' => 'p1']);

        $this->assertEquals('INVALID_FILE_FORMAT', $result->errorCode);
    }

    #[Test]
    public function it_maps_disk_space_error_to_insufficient_storage_code(): void
    {
        Log::shouldReceive('error')->once();

        $exception = new \RuntimeException('No disk space left');
        $result = $this->handler->handleAudioProcessingException($exception, ['processing_id' => 'p2']);

        $this->assertEquals('INSUFFICIENT_STORAGE', $result->errorCode);
    }

    #[Test]
    public function it_maps_permission_error_to_file_permission_code(): void
    {
        Log::shouldReceive('error')->once();

        $exception = new \RuntimeException('permission denied for path');
        $result = $this->handler->handleVideoProcessingException($exception, ['processing_id' => 'p3']);

        $this->assertEquals('FILE_PERMISSION_ERROR', $result->errorCode);
    }

    #[Test]
    public function it_maps_timeout_error_to_processing_timeout_code(): void
    {
        Log::shouldReceive('error')->once();

        $exception = new \RuntimeException('Request timeout reached');
        $result = $this->handler->handleLivestreamProcessingException($exception, ['processing_id' => 'p4']);

        $this->assertEquals('PROCESSING_TIMEOUT', $result->errorCode);
    }

    #[Test]
    public function it_returns_unknown_error_code_for_unrecognised_exception(): void
    {
        Log::shouldReceive('error')->once();

        $exception = new \RuntimeException('Totally unknown problem');
        $result = $this->handler->handleVideoProcessingException($exception, ['processing_id' => 'p5']);

        $this->assertEquals('UNKNOWN_ERROR', $result->errorCode);
    }

    // --- handleJobFailure() ---

    #[Test]
    public function it_logs_job_failure(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $message === 'Job processing failed'
                    && $context['job_class'] === 'App\Jobs\TranscribeAudio'
                    && $context['error'] === 'Whisper API down';
            });

        $exception = new \RuntimeException('Whisper API down');
        $this->handler->handleJobFailure($exception, 'App\Jobs\TranscribeAudio');
    }

    #[Test]
    public function it_includes_additional_context_in_job_failure_log(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return isset($context['sermon_id']) && $context['sermon_id'] === 42;
            });

        $exception = new \RuntimeException('failed');
        $this->handler->handleJobFailure($exception, 'SomeJob', ['sermon_id' => 42]);
    }

    // --- logProcessingMetrics() ---

    #[Test]
    public function it_logs_processing_metrics(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $message === 'Processing metrics'
                    && $context['processing_type'] === 'audio'
                    && $context['duration'] === 120;
            });

        $this->handler->logProcessingMetrics('audio', ['duration' => 120]);
    }
}
