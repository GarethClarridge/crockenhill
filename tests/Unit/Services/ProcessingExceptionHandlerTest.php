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

    // ---- handleVideoProcessingException ----

    #[Test]
    public function it_handles_video_processing_exception(): void
    {
        Log::shouldReceive('error')->once()->with('Video processing failed', \Mockery::type('array'));

        $exception = new \Exception('FFmpeg crashed');
        $context = ['processing_id' => 'vid-123'];

        $result = $this->handler->handleVideoProcessingException($exception, $context);

        $this->assertInstanceOf(ProcessingResult::class, $result);
        $this->assertFalse($result->success);
    }

    // ---- handleAudioProcessingException ----

    #[Test]
    public function it_handles_audio_processing_exception(): void
    {
        Log::shouldReceive('error')->once()->with('Audio processing failed', \Mockery::type('array'));

        $exception = new \Exception('Transcription failed');
        $context = ['processing_id' => 'aud-456'];

        $result = $this->handler->handleAudioProcessingException($exception, $context);

        $this->assertInstanceOf(ProcessingResult::class, $result);
        $this->assertFalse($result->success);
    }

    // ---- handleLivestreamProcessingException ----

    #[Test]
    public function it_handles_livestream_processing_exception(): void
    {
        Log::shouldReceive('error')->once()->with('Livestream processing failed', \Mockery::type('array'));

        $exception = new \Exception('Segmentation error');
        $context = ['processing_id' => 'live-789'];

        $result = $this->handler->handleLivestreamProcessingException($exception, $context);

        $this->assertInstanceOf(ProcessingResult::class, $result);
        $this->assertFalse($result->success);
    }

    // ---- handleJobFailure ----

    #[Test]
    public function it_handles_job_failure_with_logging(): void
    {
        Log::shouldReceive('error')->once()->with('Job processing failed', \Mockery::on(function ($data) {
            return $data['job_class'] === 'TranscribeAudio'
                && $data['error'] === 'Job timed out';
        }));

        $exception = new \Exception('Job timed out');

        $this->handler->handleJobFailure($exception, 'TranscribeAudio', []);
    }

    // ---- logProcessingMetrics ----

    #[Test]
    public function it_logs_processing_metrics(): void
    {
        Log::shouldReceive('info')->once()->with('Processing metrics', \Mockery::on(function ($data) {
            return $data['processing_type'] === 'audio'
                && $data['duration'] === 45.2
                && isset($data['timestamp']);
        }));

        $this->handler->logProcessingMetrics('audio', ['duration' => 45.2]);
    }

    // ---- User-friendly message mapping ----

    #[Test]
    public function it_maps_invalid_argument_to_friendly_message(): void
    {
        Log::shouldReceive('error')->once();

        $exception = new \InvalidArgumentException('Bad file format');
        $result = $this->handler->handleVideoProcessingException($exception, ['processing_id' => 'test']);

        $this->assertStringContainsString('format or size specifications', $result->message);
    }

    #[Test]
    public function it_maps_disk_space_message_pattern(): void
    {
        Log::shouldReceive('error')->once();

        $exception = new \Exception('Insufficient disk space on /var/storage');
        $result = $this->handler->handleAudioProcessingException($exception, ['processing_id' => 'test']);

        $this->assertStringContainsString('storage space', $result->message);
    }

    #[Test]
    public function it_maps_permission_message_pattern(): void
    {
        Log::shouldReceive('error')->once();

        $exception = new \Exception('File permission denied for /tmp/video.mp4');
        $result = $this->handler->handleVideoProcessingException($exception, ['processing_id' => 'test']);

        $this->assertStringContainsString('permission error', $result->message);
    }

    #[Test]
    public function it_maps_timeout_message_pattern(): void
    {
        Log::shouldReceive('error')->once();

        $exception = new \Exception('Process timeout after 300 seconds');
        $result = $this->handler->handleVideoProcessingException($exception, ['processing_id' => 'test']);

        $this->assertStringContainsString('timed out', $result->message);
    }

    #[Test]
    public function it_returns_fallback_message_for_unknown_exceptions(): void
    {
        Log::shouldReceive('error')->once();

        $exception = new \Exception('Some completely unexpected error');
        $result = $this->handler->handleVideoProcessingException($exception, ['processing_id' => 'test']);

        $this->assertStringContainsString('unexpected error', $result->message);
    }

    // ---- Error code mapping ----

    #[Test]
    public function it_maps_invalid_argument_to_error_code(): void
    {
        Log::shouldReceive('error')->once();

        $exception = new \InvalidArgumentException('Bad input');
        $result = $this->handler->handleVideoProcessingException($exception, ['processing_id' => 'test']);

        $this->assertEquals('INVALID_FILE_FORMAT', $result->errorCode);
    }

    #[Test]
    public function it_maps_disk_space_to_error_code(): void
    {
        Log::shouldReceive('error')->once();

        $exception = new \Exception('No disk space remaining');
        $result = $this->handler->handleVideoProcessingException($exception, ['processing_id' => 'test']);

        $this->assertEquals('INSUFFICIENT_STORAGE', $result->errorCode);
    }

    #[Test]
    public function it_returns_unknown_error_code_for_unrecognised_exceptions(): void
    {
        Log::shouldReceive('error')->once();

        $exception = new \Exception('Something totally random');
        $result = $this->handler->handleVideoProcessingException($exception, ['processing_id' => 'test']);

        $this->assertEquals('UNKNOWN_ERROR', $result->errorCode);
    }
}
