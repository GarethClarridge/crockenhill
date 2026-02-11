<?php

namespace Tests\Unit\Services;

use App\Models\MediaProcessingLog;
use App\Services\LivestreamErrorHandler;
use App\Services\LivestreamProcessingLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LivestreamErrorHandlerTest extends TestCase
{
    use RefreshDatabase;

    private LivestreamErrorHandler $errorHandler;

    private LivestreamProcessingLogger $mockLogger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockLogger = Mockery::mock(LivestreamProcessingLogger::class);
        $this->errorHandler = new LivestreamErrorHandler($this->mockLogger);

        Mail::fake();
        Config::set('media-processing.email.admin_email', 'admin@test.com');
    }

    #[Test]
    public function it_handles_processing_failure(): void
    {
        $processing = MediaProcessingLog::factory()->livestream()->processing()->create([
            'processing_id' => 'test-processing-id',
        ]);

        $exception = new \RuntimeException('Test error message');
        $step = 'video_analysis';

        $this->mockLogger->shouldReceive('logError')
            ->once()
            ->with('test-processing-id', $step, $exception);

        $this->errorHandler->handleProcessingFailure('test-processing-id', $exception, $step);

        $processing->refresh();
        $this->assertTrue($processing->isFailed());
        $this->assertEquals('Test error message', $processing->error_message);

        Mail::assertQueued(\App\Mail\LivestreamProcessingFailed::class);
    }

    #[Test]
    public function it_handles_partial_failure(): void
    {
        $processing = MediaProcessingLog::factory()->livestream()->processing()->create([
            'processing_id' => 'test-processing-id',
        ]);

        $step = 'video_extraction';
        $message = 'Video quality reduced due to codec issues';
        $context = ['codec' => 'h264', 'quality' => 'reduced'];

        $this->mockLogger->shouldReceive('logWarning')
            ->once()
            ->with('test-processing-id', $step, $message, $context);

        $this->errorHandler->handlePartialFailure('test-processing-id', $step, $message, $context);

        $processing->refresh();
        $this->assertTrue($processing->isComplete());
        $this->assertEquals($message, $processing->error_message);
    }

    #[Test]
    public function it_retries_on_timeout_exception(): void
    {
        $process = new \Symfony\Component\Process\Process(['echo', 'test']);
        $exception = new \Symfony\Component\Process\Exception\ProcessTimedOutException($process, \Symfony\Component\Process\Exception\ProcessTimedOutException::TYPE_GENERAL);

        $this->assertTrue($this->errorHandler->shouldRetry($exception, 1));
        $this->assertTrue($this->errorHandler->shouldRetry($exception, 2));
        $this->assertFalse($this->errorHandler->shouldRetry($exception, 3));
    }

    #[Test]
    public function it_does_not_retry_non_retryable_exceptions(): void
    {
        $exception = new \InvalidArgumentException('Invalid file format');

        $this->assertFalse($this->errorHandler->shouldRetry($exception, 1));
    }

    #[Test]
    public function it_retries_on_timeout_message(): void
    {
        $exception = new \RuntimeException('Connection timed out while processing');

        $this->assertTrue($this->errorHandler->shouldRetry($exception, 1));
    }

    #[Test]
    public function it_calculates_exponential_retry_delay(): void
    {
        Config::set('media-processing.retry_base_delay', 60);
        Config::set('media-processing.retry_max_delay', 3600);

        $this->assertEquals(60, $this->errorHandler->getRetryDelay(1));
        $this->assertEquals(120, $this->errorHandler->getRetryDelay(2));
        $this->assertEquals(240, $this->errorHandler->getRetryDelay(3));
        $this->assertEquals(3600, $this->errorHandler->getRetryDelay(10));
    }

    #[Test]
    public function it_handles_segmentation_failure(): void
    {
        $processing = MediaProcessingLog::factory()->livestream()->processing()->create([
            'processing_id' => 'test-processing-id',
        ]);

        $reason = 'No clear speech segments found';
        $segments = [
            ['start' => 0, 'end' => 180, 'classification' => 'song'],
            ['start' => 180, 'end' => 360, 'classification' => 'song'],
        ];

        $this->mockLogger->shouldReceive('logWarning')
            ->once()
            ->with('test-processing-id', 'segmentation', "Segmentation issues: {$reason}", Mockery::any());

        $this->errorHandler->handleSegmentationFailure('test-processing-id', $reason, $segments);

        $processing->refresh();
        $this->assertTrue($processing->isFailed());
        $this->assertStringContainsString($reason, $processing->error_message);

        Mail::assertQueued(\App\Mail\ManualReviewRequired::class);
    }

    #[Test]
    public function it_handles_video_extraction_failure(): void
    {
        $processing = MediaProcessingLog::factory()->livestream()->processing()->create([
            'processing_id' => 'test-processing-id',
        ]);

        $exception = new \RuntimeException('FFmpeg failed to extract video segment');

        $this->mockLogger->shouldReceive('logWarning')
            ->once()
            ->with('test-processing-id', 'video_extraction', 'Video extraction failed, continuing with audio-only processing', Mockery::any());

        $this->errorHandler->handleVideoExtractionFailure('test-processing-id', $exception);

        $processing->refresh();
        $this->assertStringContainsString('Video extraction failed', $processing->error_message);
    }

    #[Test]
    public function it_handles_disk_space_error(): void
    {
        $processing = MediaProcessingLog::factory()->livestream()->processing()->create([
            'processing_id' => 'test-processing-id',
        ]);

        $exception = new \RuntimeException('No space left on device');

        $this->mockLogger->shouldReceive('logError')
            ->once()
            ->with('test-processing-id', 'storage', $exception);

        $result = $this->errorHandler->handleStorageError('test-processing-id', $exception, 'file_write');

        $this->assertFalse($result);

        $processing->refresh();
        $this->assertTrue($processing->isFailed());
        $this->assertEquals('Insufficient disk space for processing', $processing->error_message);

        Mail::assertQueued(\App\Mail\DiskSpaceWarning::class);
    }

    #[Test]
    public function it_handles_permission_error(): void
    {
        $processing = MediaProcessingLog::factory()->livestream()->processing()->create([
            'processing_id' => 'test-processing-id',
        ]);

        $exception = new \RuntimeException('Permission denied');
        $operation = 'video_extraction';

        $this->mockLogger->shouldReceive('logError')
            ->once()
            ->with('test-processing-id', 'storage', $exception);

        $result = $this->errorHandler->handleStorageError('test-processing-id', $exception, $operation);

        $this->assertFalse($result);

        $processing->refresh();
        $this->assertTrue($processing->isFailed());
        $this->assertStringContainsString($operation, $processing->error_message);

        Mail::assertQueued(\App\Mail\PermissionError::class);
    }

    #[Test]
    public function it_validates_valid_file_format(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test').'.mp4';
        file_put_contents($tempFile, str_repeat('x', 500));

        $errors = $this->errorHandler->validateFileFormat($tempFile);

        $this->assertEmpty($errors);

        unlink($tempFile);
    }

    #[Test]
    public function it_rejects_unsupported_file_formats(): void
    {
        $errors = $this->errorHandler->validateFileFormat('/path/to/file.wmv');

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Unsupported file format', $errors[0]);
    }

    #[Test]
    public function it_rejects_files_exceeding_size_limit(): void
    {
        Config::set('media-processing.types.livestream.max_file_size', 100);

        $tempFile = tempnam(sys_get_temp_dir(), 'test').'.mp4';
        file_put_contents($tempFile, str_repeat('x', 200));

        $errors = $this->errorHandler->validateFileFormat($tempFile);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('File size exceeds maximum', $errors[0]);

        unlink($tempFile);
    }

    #[Test]
    public function it_checks_system_requirements(): void
    {
        Config::set('media-processing.ffmpeg.ffmpeg_path', '/nonexistent/ffmpeg');
        Config::set('media-processing.ffmpeg.ffprobe_path', '/nonexistent/ffprobe');

        $errors = $this->errorHandler->checkSystemRequirements();

        $this->assertGreaterThan(0, count($errors));
    }

    #[Test]
    public function it_performs_graceful_degradation_with_fallback_action(): void
    {
        $fallbackCalled = false;
        $fallbackAction = function () use (&$fallbackCalled) {
            $fallbackCalled = true;
        };

        $this->mockLogger->shouldReceive('logWarning')
            ->once()
            ->with('test-processing-id', 'degradation', 'Graceful degradation triggered: Test reason');

        $this->mockLogger->shouldReceive('logProcessingStep')
            ->once()
            ->with('test-processing-id', 'fallback_action_completed');

        $this->errorHandler->gracefulDegradation('test-processing-id', 'Test reason', $fallbackAction);

        $this->assertTrue($fallbackCalled);
    }

    #[Test]
    public function it_handles_graceful_degradation_when_fallback_fails(): void
    {
        $fallbackAction = function () {
            throw new \RuntimeException('Fallback failed');
        };

        $this->mockLogger->shouldReceive('logWarning')
            ->once()
            ->with('test-processing-id', 'degradation', 'Graceful degradation triggered: Test reason');

        $this->mockLogger->shouldReceive('logError')
            ->once()
            ->with('test-processing-id', 'fallback_action', Mockery::type(\Exception::class));

        $this->errorHandler->gracefulDegradation('test-processing-id', 'Test reason', $fallbackAction);
    }
}
