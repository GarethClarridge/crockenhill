<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Data\ProcessingReport;
use App\Enums\ProcessingStatus;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Services\Processing\SermonProcessingLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonProcessingLoggerTest extends TestCase
{
    use RefreshDatabase;

    private SermonProcessingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        MediaProcessingLog::query()->delete();

        Log::spy();

        $this->logger = new SermonProcessingLogger;

        Log::spy();
    }

    // --- logProcessingStart() ---

    #[Test]
    public function it_logs_processing_start_as_info(): void
    {
        $this->logger->logProcessingStart('proc-001', 'sermon.mp3');

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $message === 'Sermon processing started'
                    && $context['processing_id'] === 'proc-001'
                    && $context['filename'] === 'sermon.mp3';
            });
    }

    #[Test]
    public function it_includes_metadata_in_processing_start_log(): void
    {
        $this->logger->logProcessingStart('proc-001', 'sermon.mp3', ['preacher' => 'John']);

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $context['metadata']['preacher'] === 'John';
            });
    }

    #[Test]
    public function it_includes_memory_usage_in_processing_start(): void
    {
        $this->logger->logProcessingStart('proc-001', 'sermon.mp3');

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return isset($context['memory_usage']) && isset($context['peak_memory']);
            });
    }

    // --- logProcessingStep() ---

    #[Test]
    public function it_logs_completed_step_as_info(): void
    {
        $this->logger->logProcessingStep('proc-001', 'transcription', 'completed');

        Log::shouldHaveReceived('log')
            ->once()
            ->withArgs(function (string $level, string $message) {
                return $level === 'info' && str_contains($message, 'transcription') && str_contains($message, 'completed');
            });
    }

    #[Test]
    public function it_logs_failed_step_as_error(): void
    {
        $this->logger->logProcessingStep('proc-001', 'transcription', 'failed');

        Log::shouldHaveReceived('log')
            ->once()
            ->withArgs(function (string $level) {
                return $level === 'error';
            });
    }

    #[Test]
    public function it_logs_degraded_step_as_warning(): void
    {
        $this->logger->logProcessingStep('proc-001', 'analysis', 'degraded');

        Log::shouldHaveReceived('log')
            ->once()
            ->withArgs(function (string $level) {
                return $level === 'warning';
            });
    }

    #[Test]
    public function it_logs_unknown_status_as_debug(): void
    {
        $this->logger->logProcessingStep('proc-001', 'validation', 'started');

        Log::shouldHaveReceived('log')
            ->once()
            ->withArgs(function (string $level) {
                return $level === 'debug';
            });
    }

    #[Test]
    public function it_includes_error_message_in_step_log_when_provided(): void
    {
        $this->logger->logProcessingStep('proc-001', 'analysis', 'failed', [], 'API rate limited');

        Log::shouldHaveReceived('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                return isset($context['error_message']) && $context['error_message'] === 'API rate limited';
            });
    }

    // --- logApiCall() ---

    #[Test]
    public function it_logs_successful_api_call_as_info(): void
    {
        $this->logger->logApiCall('proc-001', 'openai', '/v1/audio/transcriptions', 1.5, 200);

        Log::shouldHaveReceived('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                return $level === 'info'
                    && $context['service'] === 'openai'
                    && $context['status_code'] === 200
                    && $context['response_time_ms'] > 0;
            });
    }

    #[Test]
    public function it_logs_failed_api_call_as_error(): void
    {
        $this->logger->logApiCall('proc-001', 'openai', '/v1/audio/transcriptions', 0.5, 500, 'Server error');

        Log::shouldHaveReceived('log')
            ->once()
            ->withArgs(function (string $level) {
                return $level === 'error';
            });
    }

    // --- logFileOperation() ---

    #[Test]
    public function it_logs_file_operation_with_size_and_time(): void
    {
        $this->logger->logFileOperation('proc-001', 'upload', '/path/sermon.mp3', 1048576, 0.25);

        Log::shouldHaveReceived('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                return $level === 'info'
                    && $context['operation'] === 'upload'
                    && $context['file_size_bytes'] === 1048576
                    && isset($context['file_size_human'])
                    && $context['operation_time_ms'] > 0;
            });
    }

    #[Test]
    public function it_logs_file_operation_error(): void
    {
        $this->logger->logFileOperation('proc-001', 'delete', '/path/file.mp3', null, null, 'Permission denied');

        Log::shouldHaveReceived('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                return $level === 'error' && isset($context['error_message']);
            });
    }

    // --- logProcessingComplete() ---

    #[Test]
    public function it_logs_successful_completion_as_info(): void
    {
        $this->logger->logProcessingComplete('proc-001', ProcessingStatus::Completed, ['sermon_id' => 42]);

        Log::shouldHaveReceived('log')
            ->once()
            ->withArgs(function (string $level, string $message) {
                return $level === 'info' && str_contains($message, 'completed');
            });
    }

    #[Test]
    public function it_logs_failed_completion_as_error(): void
    {
        $this->logger->logProcessingComplete('proc-001', ProcessingStatus::Failed, [], 'Disk full');

        Log::shouldHaveReceived('log')
            ->once()
            ->withArgs(function (string $level) {
                return $level === 'error';
            });
    }

    #[Test]
    public function it_logs_cancelled_completion_as_info(): void
    {
        $this->logger->logProcessingComplete('proc-001', ProcessingStatus::Cancelled, [], 'User requested');

        Log::shouldHaveReceived('log')
            ->once()
            ->withArgs(function (string $level, string $message) {
                return $level === 'info' && str_contains($message, 'cancelled');
            });
    }

    // --- logError() ---

    #[Test]
    public function it_logs_exception_with_full_context(): void
    {
        $exception = new \RuntimeException('API down');
        $this->logger->logError('proc-001', 'transcription', $exception);

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return str_contains($message, 'transcription')
                    && $context['processing_id'] === 'proc-001'
                    && $context['exception_class'] === 'RuntimeException'
                    && isset($context['stack_trace']);
            });
    }

    // --- logHealthCheck() ---

    #[Test]
    public function it_sanitizes_log_data_to_prevent_log_injection(): void
    {
        Log::spy();

        // Input with control characters (potential log injection)
        $maliciousFilename = "sermon\ntitle\r.mp3";

        $this->logger->logProcessingStart('proc-001', $maliciousFilename);

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(function (string $message, array $context) {
                // The newline and carriage return should be replaced by spaces
                return ! str_contains($context['filename'], "\n")
                    && ! str_contains($context['filename'], "\r")
                    && str_contains($context['filename'], 'sermon title');
            });
    }

    #[Test]
    public function it_logs_healthy_check_as_info(): void
    {
        $this->logger->logHealthCheck('queue', ['status' => 'healthy']);

        Log::shouldHaveReceived('log')
            ->once()
            ->withArgs(function (string $level) {
                return $level === 'info';
            });
    }

    #[Test]
    public function it_logs_degraded_check_as_warning(): void
    {
        $this->logger->logHealthCheck('storage', ['status' => 'degraded']);

        Log::shouldHaveReceived('log')
            ->once()
            ->withArgs(function (string $level) {
                return $level === 'warning';
            });
    }

    #[Test]
    public function it_logs_error_check_as_error(): void
    {
        $this->logger->logHealthCheck('processing', ['status' => 'error']);

        Log::shouldHaveReceived('log')
            ->once()
            ->withArgs(function (string $level) {
                return $level === 'error';
            });
    }

    #[Test]
    public function it_logs_a_result_without_a_status_as_debug_without_erroring(): void
    {
        $this->logger->logHealthCheck('mystery', ['message' => 'no status key']);

        Log::shouldHaveReceived('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                return $level === 'debug' && $context['status'] === 'unknown';
            });
    }

    // --- generateProcessingStatistics() ---

    #[Test]
    public function it_generates_statistics_with_correct_counts(): void
    {
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
        MediaProcessingLog::factory()->audio()->completed()->count(8)->create();
        MediaProcessingLog::factory()->audio()->failed()->count(2)->create();

        $stats = $this->logger->generateProcessingStatistics();

        $this->assertEquals(80.0, $stats['success_rate']);
    }

    #[Test]
    public function it_returns_zero_success_rate_when_no_records(): void
    {
        $stats = $this->logger->generateProcessingStatistics();

        $this->assertEquals(0, $stats['success_rate']);
    }

    #[Test]
    public function it_categorises_api_errors(): void
    {
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
        MediaProcessingLog::factory()->audio()->failed()->create([
            'error_message' => 'No disk space left on storage device',
        ]);

        $stats = $this->logger->generateProcessingStatistics();

        $this->assertArrayHasKey('STORAGE_ERROR', $stats['error_patterns']);
    }

    #[Test]
    public function it_categorises_transcription_errors(): void
    {
        MediaProcessingLog::factory()->audio()->failed()->create([
            'error_message' => 'Whisper transcription service unavailable',
        ]);

        $stats = $this->logger->generateProcessingStatistics();

        $this->assertArrayHasKey('TRANSCRIPTION_ERROR', $stats['error_patterns']);
    }

    #[Test]
    public function it_categorises_analysis_errors(): void
    {
        MediaProcessingLog::factory()->audio()->failed()->create([
            'error_message' => 'Failed to parse JSON analysis response',
        ]);

        $stats = $this->logger->generateProcessingStatistics();

        $this->assertArrayHasKey('ANALYSIS_ERROR', $stats['error_patterns']);
    }

    #[Test]
    public function it_categorises_database_errors(): void
    {
        MediaProcessingLog::factory()->audio()->failed()->create([
            'error_message' => 'Database connection timeout during save',
        ]);

        $stats = $this->logger->generateProcessingStatistics();

        $this->assertArrayHasKey('DATABASE_ERROR', $stats['error_patterns']);
    }

    #[Test]
    public function it_categorises_network_errors(): void
    {
        MediaProcessingLog::factory()->audio()->failed()->create([
            'error_message' => 'Network unreachable while fetching bible text',
        ]);

        $stats = $this->logger->generateProcessingStatistics();

        $this->assertArrayHasKey('NETWORK_ERROR', $stats['error_patterns']);
    }

    #[Test]
    public function it_calculates_average_processing_time(): void
    {
        $now = now();
        MediaProcessingLog::factory()->audio()->completed()->create([
            'created_at' => $now,
            'updated_at' => $now->copy()->addMinutes(10),
        ]);
        MediaProcessingLog::factory()->audio()->completed()->create([
            'created_at' => $now,
            'updated_at' => $now->copy()->addMinutes(20),
        ]);

        $stats = $this->logger->generateProcessingStatistics();

        // (10 + 20) / 2 = 15 minutes = 900 seconds
        $this->assertEquals(900, $stats['average_processing_time']);
    }

    #[Test]
    public function it_handles_null_dates_when_calculating_average_processing_time(): void
    {
        MediaProcessingLog::factory()->audio()->completed()->create([
            'created_at' => null,
            'updated_at' => now(),
        ]);

        $stats = $this->logger->generateProcessingStatistics();

        $this->assertEquals(0, $stats['average_processing_time']);
    }

    #[Test]
    public function it_categorises_unknown_errors_as_other(): void
    {
        MediaProcessingLog::factory()->audio()->failed()->create([
            'error_message' => 'Something completely unexpected happened',
        ]);

        $stats = $this->logger->generateProcessingStatistics();

        $this->assertArrayHasKey('OTHER_ERROR', $stats['error_patterns']);
    }

    #[Test]
    public function it_includes_period_information(): void
    {
        $stats = $this->logger->generateProcessingStatistics(days: 14);

        $this->assertEquals(14, $stats['period']['days']);
        $this->assertArrayHasKey('start', $stats['period']);
        $this->assertArrayHasKey('end', $stats['period']);
    }

    // --- logWarning() ---

    #[Test]
    public function it_logs_warning_with_step_and_message(): void
    {
        $this->logger->logWarning('proc-001', 'extraction', 'Low disk space');

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return str_contains($message, 'extraction')
                    && $context['processing_id'] === 'proc-001'
                    && $context['step'] === 'extraction'
                    && $context['warning_message'] === 'Low disk space'
                    && isset($context['timestamp']);
            });
    }

    #[Test]
    public function it_includes_context_in_warning_log(): void
    {
        $this->logger->logWarning('proc-001', 'video', 'Quality reduced', ['quality' => 'low']);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $context['context']['quality'] === 'low';
            });
    }

    // --- generateProcessingReport() ---

    #[Test]
    public function it_generates_processing_report_for_existing_record(): void
    {
        $processing = MediaProcessingLog::factory()->create([
            'processing_id' => 'test-report-id',
            'status' => 'completed',
            'original_filename' => 'test-video.mp4',
            'file_size' => 1073741824,
            'duration' => 3600.0,
            'sermon_id' => null,
            'completed_at' => now(),
        ]);

        LivestreamSegment::factory()->count(3)->create(['media_processing_log_id' => $processing->id]);
        LivestreamSegment::factory()->create([
            'media_processing_log_id' => $processing->id,
            'classification' => 'speech',
            'duration' => 1800.0,
            'is_sermon_candidate' => true,
        ]);

        $report = $this->logger->generateProcessingReport('test-report-id');

        $this->assertInstanceOf(ProcessingReport::class, $report);
        $data = $report->toArray();
        $this->assertEquals('test-report-id', $data['processing_id']);
        $this->assertEquals('completed', $data['status']);
        $this->assertEquals('test-video.mp4', $data['original_filename']);
        $this->assertEquals(4, $data['total_segments']);
        $this->assertEquals('not_started', $data['sermon_processing_status']);
    }

    #[Test]
    public function it_includes_parsed_logs_and_metrics_in_processing_report(): void
    {
        $processingId = 'test-log-parsing-'.Str::random(4);

        // Create a unique temporary storage path for this test to avoid parallel race conditions on laravel.log
        $tempStorage = storage_path('testing/sermon_logger_test_'.Str::random(8));
        $tempLogDir = $tempStorage.'/logs';
        if (! is_dir($tempLogDir)) {
            mkdir($tempLogDir, 0777, true);
        }
        $tempLogPath = $tempLogDir.'/laravel.log';

        // Redirect storage_path() for the duration of this test
        $originalStoragePath = $this->app->storagePath();
        $this->app->useStoragePath($tempStorage);

        MediaProcessingLog::factory()->create([
            'processing_id' => $processingId,
            'status' => 'completed',
        ]);

        $logEntries = [
            "[2025-05-20 10:00:00] local.ERROR: Processing error in step: validation {\"processing_id\":\"{$processingId}\"}",
            "[2025-05-20 10:05:00] local.WARNING: Processing warning in step: extraction {\"processing_id\":\"{$processingId}\"}",
            "[2025-05-20 10:10:00] local.INFO: Performance metrics {\"processing_id\":\"{$processingId}\",\"step\":\"transcription\",\"execution_time_seconds\":42.5}",
        ];

        file_put_contents($tempLogPath, implode("\n", $logEntries)."\n");

        try {
            $report = $this->logger->generateProcessingReport($processingId);
            $data = $report->toArray();

            $this->assertCount(1, $data['errors']);
            $this->assertCount(1, $data['warnings']);
            $this->assertArrayHasKey('transcription', $data['performance_metrics']);
            $this->assertEquals(42.5, $data['performance_metrics']['transcription']['execution_time']);
        } finally {
            // Restore original storage path
            $this->app->useStoragePath($originalStoragePath);

            // Cleanup temp storage
            if (is_file($tempLogPath)) {
                unlink($tempLogPath);
            }
            if (is_dir($tempLogDir)) {
                rmdir($tempLogDir);
            }
            if (is_dir($tempStorage)) {
                rmdir($tempStorage);
            }
        }
    }

    #[Test]
    public function it_throws_when_generating_report_for_missing_record(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Processing record not found for ID: nonexistent-id');

        $this->logger->generateProcessingReport('nonexistent-id');
    }

    // --- getRecentProcessingActivity() ---

    #[Test]
    public function it_returns_recent_livestream_processing_activity(): void
    {
        MediaProcessingLog::factory()->livestream()->create([
            'status' => 'completed',
            'created_at' => now()->subHours(2),
            'completed_at' => now()->subHours(1),
        ]);
        MediaProcessingLog::factory()->livestream()->create([
            'status' => 'failed',
            'created_at' => now()->subHours(1),
        ]);
        MediaProcessingLog::factory()->livestream()->create([
            'status' => 'processing',
            'created_at' => now()->subMinutes(30),
        ]);
        // Old record outside the 24-hour window should be excluded
        MediaProcessingLog::factory()->livestream()->create([
            'status' => 'completed',
            'created_at' => now()->subDays(2),
        ]);

        $activity = $this->logger->getRecentProcessingActivity(24);

        $this->assertEquals(3, $activity['total_processed']);
        $this->assertEquals(1, $activity['successful']);
        $this->assertEquals(1, $activity['failed']);
        $this->assertEquals(1, $activity['in_progress']);
    }
}
