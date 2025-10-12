<?php

namespace Tests\Unit\Services;

use App\Models\MediaProcessingLog;
use App\Services\LivestreamErrorHandler;
use App\Services\LivestreamProcessingLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Mockery;
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
        Config::set('media-processing.admin_email', 'admin@test.com');
    }

    public function test_handle_processing_failure()
    {
        $processing = MediaProcessingLog::factory()->create([
            'processing_id' => 'test-processing-id',
            'status' => 'processing',
        ]);

        $exception = new \Exception('Test error message');
        $step = 'video_analysis';

        $this->mockLogger->shouldReceive('logError')
            ->once()
            ->with('test-processing-id', $step, $exception);

        $this->errorHandler->handleProcessingFailure('test-processing-id', $exception, $step);

        $processing->refresh();
        $this->assertEquals('failed', $processing->status->value);
        $this->assertEquals('Test error message', $processing->error_message);

        Mail::assertSent(\App\Mail\LivestreamProcessingFailed::class, function ($mail) {
            return $mail->processingId === 'test-processing-id' &&
                   $mail->step === 'video_analysis';
        });
    }

    public function test_handle_partial_failure()
    {
        $processing = MediaProcessingLog::factory()->create([
            'processing_id' => 'test-processing-id',
            'status' => 'processing',
        ]);

        $step = 'video_extraction';
        $message = 'Video quality reduced due to codec issues';
        $context = ['codec' => 'h264', 'quality' => 'reduced'];

        $this->mockLogger->shouldReceive('logWarning')
            ->once()
            ->with('test-processing-id', $step, $message, $context);

        $this->errorHandler->handlePartialFailure('test-processing-id', $step, $message, $context);

        $processing->refresh();
        $this->assertEquals('completed', $processing->status->value);
        $this->assertEquals($message, $processing->error_message);
    }

    public function test_should_retry_with_retryable_exception()
    {
        $process = new \Symfony\Component\Process\Process(['echo', 'test']);
        $retryableException = new \Symfony\Component\Process\Exception\ProcessTimedOutException($process, \Symfony\Component\Process\Exception\ProcessTimedOutException::TYPE_GENERAL);

        $this->assertTrue($this->errorHandler->shouldRetry($retryableException, 1));
        $this->assertTrue($this->errorHandler->shouldRetry($retryableException, 2));
        $this->assertFalse($this->errorHandler->shouldRetry($retryableException, 3)); // Max retries reached
    }

    public function test_should_retry_with_non_retryable_exception()
    {
        $nonRetryableException = new \InvalidArgumentException('Invalid file format');

        $this->assertFalse($this->errorHandler->shouldRetry($nonRetryableException, 1));
    }

    public function test_should_retry_with_retryable_message()
    {
        $exception = new \Exception('Connection timed out while processing');

        $this->assertTrue($this->errorHandler->shouldRetry($exception, 1));
    }

    public function test_get_retry_delay()
    {
        Config::set('media-processing.retry_base_delay', 60);
        Config::set('media-processing.retry_max_delay', 3600);

        $this->assertEquals(60, $this->errorHandler->getRetryDelay(1));   // 60 * 2^0 = 60
        $this->assertEquals(120, $this->errorHandler->getRetryDelay(2));  // 60 * 2^1 = 120
        $this->assertEquals(240, $this->errorHandler->getRetryDelay(3));  // 60 * 2^2 = 240
        $this->assertEquals(3600, $this->errorHandler->getRetryDelay(10)); // Capped at max_delay
    }

    public function test_handle_segmentation_failure()
    {
        $processing = MediaProcessingLog::factory()->create([
            'processing_id' => 'test-processing-id',
            'status' => 'processing',
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
        $this->assertEquals('failed', $processing->status->value);
        $this->assertStringContainsString($reason, $processing->error_message);

        Mail::assertSent(\App\Mail\ManualReviewRequired::class, function ($mail) use ($reason, $segments) {
            return $mail->processingId === 'test-processing-id' &&
                   $mail->reason === $reason &&
                   $mail->segments === $segments;
        });
    }

    public function test_handle_video_extraction_failure()
    {
        $processing = MediaProcessingLog::factory()->create([
            'processing_id' => 'test-processing-id',
            'status' => 'processing',
        ]);

        $exception = new \Exception('FFmpeg failed to extract video segment');

        $this->mockLogger->shouldReceive('logWarning')
            ->once()
            ->with('test-processing-id', 'video_extraction', 'Video extraction failed, continuing with audio-only processing', Mockery::any());

        $this->errorHandler->handleVideoExtractionFailure('test-processing-id', $exception);

        $processing->refresh();
        $this->assertStringContainsString('Video extraction failed', $processing->error_message);
    }

    public function test_handle_storage_error_disk_space()
    {
        $processing = MediaProcessingLog::factory()->create([
            'processing_id' => 'test-processing-id',
            'status' => 'processing',
        ]);

        $exception = new \Exception('No space left on device');

        $this->mockLogger->shouldReceive('logError')
            ->once()
            ->with('test-processing-id', 'storage', $exception);

        $result = $this->errorHandler->handleStorageError('test-processing-id', $exception, 'file_write');

        $this->assertFalse($result); // Should not retry

        $processing->refresh();
        $this->assertEquals('failed', $processing->status->value);
        $this->assertEquals('Insufficient disk space for processing', $processing->error_message);

        Mail::assertSent(\App\Mail\DiskSpaceWarning::class);
    }

    public function test_handle_storage_error_permission()
    {
        $processing = MediaProcessingLog::factory()->create([
            'processing_id' => 'test-processing-id',
            'status' => 'processing',
        ]);

        $exception = new \Exception('Permission denied');
        $operation = 'video_extraction';

        $this->mockLogger->shouldReceive('logError')
            ->once()
            ->with('test-processing-id', 'storage', $exception);

        $result = $this->errorHandler->handleStorageError('test-processing-id', $exception, $operation);

        $this->assertFalse($result); // Should not retry

        $processing->refresh();
        $this->assertEquals('failed', $processing->status->value);
        $this->assertStringContainsString($operation, $processing->error_message);

        Mail::assertSent(\App\Mail\PermissionError::class, function ($mail) use ($operation) {
            return $mail->processingId === 'test-processing-id' &&
                   $mail->operation === $operation;
        });
    }

    public function test_validate_file_format_valid_file()
    {
        Config::set('media-processing.supported_formats', ['mp4', 'mov', 'avi', 'mkv']);
        Config::set('media-processing.max_file_size', 1000);

        // Create a temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'test').'.mp4';
        file_put_contents($tempFile, str_repeat('x', 500)); // 500 bytes

        $errors = $this->errorHandler->validateFileFormat($tempFile);

        $this->assertEmpty($errors);

        unlink($tempFile);
    }

    public function test_validate_file_format_invalid_format()
    {
        Config::set('media-processing.supported_formats', ['mp4', 'mov']);

        $errors = $this->errorHandler->validateFileFormat('/path/to/file.wmv');

        $this->assertCount(2, $errors); // Format error + file not exists error
        $this->assertStringContainsString('Unsupported file format: wmv', $errors[0]);
    }

    public function test_validate_file_format_file_too_large()
    {
        Config::set('media-processing.types.livestream.max_file_size', 100);

        $tempFile = tempnam(sys_get_temp_dir(), 'test').'.mp4';
        file_put_contents($tempFile, str_repeat('x', 200)); // 200 bytes, over limit

        $errors = $this->errorHandler->validateFileFormat($tempFile);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('File size exceeds maximum', $errors[0]);

        unlink($tempFile);
    }

    public function test_check_system_requirements()
    {
        Config::set('media-processing.ffmpeg_path', '/nonexistent/ffmpeg');
        Config::set('media-processing.ffprobe_path', '/nonexistent/ffprobe');

        $errors = $this->errorHandler->checkSystemRequirements();

        $this->assertGreaterThan(0, count($errors));

        // System requirement errors can be various types
        $systemErrors = [
            'FFmpeg not found',
            'FFprobe not found',
            'directory not writable',
            'Temporary directory not writable',
            'Livestream directory not writable',
        ];

        $foundValidError = false;
        foreach ($errors as $error) {
            foreach ($systemErrors as $expectedError) {
                if (str_contains($error, $expectedError)) {
                    $foundValidError = true;
                    break 2;
                }
            }
        }

        $this->assertTrue($foundValidError, 'Expected a valid system requirement error, got: '.implode(', ', $errors));
    }

    public function test_graceful_degradation()
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

    public function test_graceful_degradation_with_failing_fallback()
    {
        $fallbackAction = function () {
            throw new \Exception('Fallback failed');
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
