<?php

namespace Tests\Unit\Services;

use App\Models\MediaProcessingLog;
use App\Services\SermonProcessingLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonProcessingLoggerTest extends TestCase
{
    use RefreshDatabase;

    private SermonProcessingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new SermonProcessingLogger;
    }

    // --- logProcessingStart() ---

    #[Test]
    public function it_logs_processing_start_as_info(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $message === 'Sermon processing started'
                    && $context['processing_id'] === 'proc-001'
                    && $context['filename'] === 'sermon.mp3';
            });

        $this->logger->logProcessingStart('proc-001', 'sermon.mp3');
    }

    #[Test]
    public function it_includes_metadata_in_processing_start_log(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $context['metadata']['preacher'] === 'John';
            });

        $this->logger->logProcessingStart('proc-001', 'sermon.mp3', ['preacher' => 'John']);
    }

    #[Test]
    public function it_includes_memory_usage_in_processing_start(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return isset($context['memory_usage']) && isset($context['peak_memory']);
            });

        $this->logger->logProcessingStart('proc-001', 'sermon.mp3');
    }

    // --- logProcessingStep() ---

    #[Test]
    public function it_logs_completed_step_as_info(): void
    {
        Log::shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message) {
                return $level === 'info' && str_contains($message, 'transcription') && str_contains($message, 'completed');
            });

        $this->logger->logProcessingStep('proc-001', 'transcription', 'completed');
    }

    #[Test]
    public function it_logs_failed_step_as_error(): void
    {
        Log::shouldReceive('log')
            ->once()
            ->withArgs(function (string $level) {
                return $level === 'error';
            });

        $this->logger->logProcessingStep('proc-001', 'transcription', 'failed');
    }

    #[Test]
    public function it_logs_degraded_step_as_warning(): void
    {
        Log::shouldReceive('log')
            ->once()
            ->withArgs(function (string $level) {
                return $level === 'warning';
            });

        $this->logger->logProcessingStep('proc-001', 'analysis', 'degraded');
    }

    #[Test]
    public function it_logs_unknown_status_as_debug(): void
    {
        Log::shouldReceive('log')
            ->once()
            ->withArgs(function (string $level) {
                return $level === 'debug';
            });

        $this->logger->logProcessingStep('proc-001', 'validation', 'started');
    }

    #[Test]
    public function it_includes_error_message_in_step_log_when_provided(): void
    {
        Log::shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                return isset($context['error_message']) && $context['error_message'] === 'API rate limited';
            });

        $this->logger->logProcessingStep('proc-001', 'analysis', 'failed', [], 'API rate limited');
    }

    // --- logApiCall() ---

    #[Test]
    public function it_logs_successful_api_call_as_info(): void
    {
        Log::shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                return $level === 'info'
                    && $context['service'] === 'openai'
                    && $context['status_code'] === 200
                    && $context['response_time_ms'] > 0;
            });

        $this->logger->logApiCall('proc-001', 'openai', '/v1/audio/transcriptions', 1.5, 200);
    }

    #[Test]
    public function it_logs_failed_api_call_as_error(): void
    {
        Log::shouldReceive('log')
            ->once()
            ->withArgs(function (string $level) {
                return $level === 'error';
            });

        $this->logger->logApiCall('proc-001', 'openai', '/v1/audio/transcriptions', 0.5, 500, 'Server error');
    }

    // --- logFileOperation() ---

    #[Test]
    public function it_logs_file_operation_with_size_and_time(): void
    {
        Log::shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                return $level === 'info'
                    && $context['operation'] === 'upload'
                    && $context['file_size_bytes'] === 1048576
                    && isset($context['file_size_human'])
                    && $context['operation_time_ms'] > 0;
            });

        $this->logger->logFileOperation('proc-001', 'upload', '/path/sermon.mp3', 1048576, 0.25);
    }

    #[Test]
    public function it_logs_file_operation_error(): void
    {
        Log::shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                return $level === 'error' && isset($context['error_message']);
            });

        $this->logger->logFileOperation('proc-001', 'delete', '/path/file.mp3', null, null, 'Permission denied');
    }

    // --- logProcessingComplete() ---

    #[Test]
    public function it_logs_successful_completion_as_info(): void
    {
        Log::shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message) {
                return $level === 'info' && str_contains($message, 'completed');
            });

        $this->logger->logProcessingComplete('proc-001', 'completed', ['sermon_id' => 42]);
    }

    #[Test]
    public function it_logs_failed_completion_as_error(): void
    {
        Log::shouldReceive('log')
            ->once()
            ->withArgs(function (string $level) {
                return $level === 'error';
            });

        $this->logger->logProcessingComplete('proc-001', 'failed', [], 'Disk full');
    }

    // --- logError() ---

    #[Test]
    public function it_logs_exception_with_full_context(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return str_contains($message, 'transcription')
                    && $context['processing_id'] === 'proc-001'
                    && $context['exception_class'] === 'RuntimeException'
                    && isset($context['stack_trace']);
            });

        $exception = new \RuntimeException('API down');
        $this->logger->logError('proc-001', 'transcription', $exception);
    }

    // --- logHealthCheck() ---

    #[Test]
    public function it_logs_healthy_check_as_info(): void
    {
        Log::shouldReceive('log')
            ->once()
            ->withArgs(function (string $level) {
                return $level === 'info';
            });

        $this->logger->logHealthCheck('queue', ['status' => 'healthy']);
    }

    #[Test]
    public function it_logs_degraded_check_as_warning(): void
    {
        Log::shouldReceive('log')
            ->once()
            ->withArgs(function (string $level) {
                return $level === 'warning';
            });

        $this->logger->logHealthCheck('storage', ['status' => 'degraded']);
    }

    #[Test]
    public function it_logs_error_check_as_error(): void
    {
        Log::shouldReceive('log')
            ->once()
            ->withArgs(function (string $level) {
                return $level === 'error';
            });

        $this->logger->logHealthCheck('processing', ['status' => 'error']);
    }

    // --- logProcessingCompletion() ---

    #[Test]
    public function it_logs_successful_processing_completion(): void
    {
        Log::shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message) {
                return $level === 'info' && str_contains($message, 'successfully');
            });

        $this->logger->logProcessingCompletion('proc-001', true, 'Done');
    }

    #[Test]
    public function it_logs_failed_processing_completion(): void
    {
        Log::shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message) {
                return $level === 'error' && str_contains($message, 'failed');
            });

        $this->logger->logProcessingCompletion('proc-001', false, 'Timeout');
    }

    // --- generateProcessingStatistics() ---

    #[Test]
    public function it_generates_statistics_with_correct_counts(): void
    {
        Log::spy();

        MediaProcessingLog::factory()->audio()->completed()->count(5)->create();
        MediaProcessingLog::factory()->audio()->failed()->count(2)->create();
        MediaProcessingLog::factory()->audio()->pending()->count(1)->create();
        MediaProcessingLog::factory()->audio()->processing()->count(1)->create();

        $stats = $this->logger->generateProcessingStatistics(days: 7);

        $this->assertEquals(9, $stats['totals']['processed']);
        $this->assertEquals(5, $stats['totals']['completed']);
        $this->assertEquals(2, $stats['totals']['failed']);
        $this->assertEquals(1, $stats['totals']['pending']);
        $this->assertEquals(1, $stats['totals']['processing']);
    }

    #[Test]
    public function it_calculates_success_rate(): void
    {
        Log::spy();

        MediaProcessingLog::factory()->audio()->completed()->count(8)->create();
        MediaProcessingLog::factory()->audio()->failed()->count(2)->create();

        $stats = $this->logger->generateProcessingStatistics();

        $this->assertEquals(80.0, $stats['success_rate']);
    }

    #[Test]
    public function it_returns_zero_success_rate_when_no_records(): void
    {
        Log::spy();

        $stats = $this->logger->generateProcessingStatistics();

        $this->assertEquals(0, $stats['success_rate']);
    }

    #[Test]
    public function it_categorises_api_errors(): void
    {
        Log::spy();

        MediaProcessingLog::factory()->audio()->failed()->create([
            'error_message' => 'OpenAI API rate limit exceeded',
        ]);

        $stats = $this->logger->generateProcessingStatistics();

        $this->assertArrayHasKey('API_ERROR', $stats['error_patterns']);
        $this->assertEquals(1, $stats['error_patterns']['API_ERROR']);
    }

    #[Test]
    public function it_categorises_storage_errors(): void
    {
        Log::spy();

        MediaProcessingLog::factory()->audio()->failed()->create([
            'error_message' => 'No disk space left on storage device',
        ]);

        $stats = $this->logger->generateProcessingStatistics();

        $this->assertArrayHasKey('STORAGE_ERROR', $stats['error_patterns']);
    }

    #[Test]
    public function it_categorises_transcription_errors(): void
    {
        Log::spy();

        MediaProcessingLog::factory()->audio()->failed()->create([
            'error_message' => 'Whisper transcription service unavailable',
        ]);

        $stats = $this->logger->generateProcessingStatistics();

        $this->assertArrayHasKey('TRANSCRIPTION_ERROR', $stats['error_patterns']);
    }

    #[Test]
    public function it_categorises_unknown_errors_as_other(): void
    {
        Log::spy();

        MediaProcessingLog::factory()->audio()->failed()->create([
            'error_message' => 'Something completely unexpected happened',
        ]);

        $stats = $this->logger->generateProcessingStatistics();

        $this->assertArrayHasKey('OTHER_ERROR', $stats['error_patterns']);
    }

    #[Test]
    public function it_includes_period_information(): void
    {
        Log::spy();

        $stats = $this->logger->generateProcessingStatistics(days: 14);

        $this->assertEquals(14, $stats['period']['days']);
        $this->assertArrayHasKey('start', $stats['period']);
        $this->assertArrayHasKey('end', $stats['period']);
    }
}
