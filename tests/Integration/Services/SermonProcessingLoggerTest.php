<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Services\Processing\SermonProcessingLogger;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonProcessingLoggerTest extends TestCase
{
    private SermonProcessingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        Log::spy();
        $this->logger = new SermonProcessingLogger;
    }

    #[Test]
    public function it_logs_processing_steps_at_the_level_matching_their_status(): void
    {
        $this->logger->logProcessingStep('proc-001', 'transcription', 'completed');
        $this->logger->logProcessingStep('proc-001', 'transcription', 'failed');
        $this->logger->logProcessingStep('proc-001', 'analysis', 'degraded');
        $this->logger->logProcessingStep('proc-001', 'validation', 'started');

        foreach (['info', 'error', 'warning', 'debug'] as $level) {
            Log::shouldHaveReceived('log')->withArgs(
                fn (string $actualLevel): bool => $actualLevel === $level,
            )->once();
        }
    }

    #[Test]
    public function it_includes_an_error_message_in_a_failed_step(): void
    {
        $this->logger->logProcessingStep('proc-001', 'analysis', 'failed', [], 'API rate limited');

        Log::shouldHaveReceived('log')->withArgs(
            fn (string $level, string $message, array $context): bool => $level === 'error'
                && $context['error_message'] === 'API rate limited',
        )->once();
    }

    #[Test]
    public function it_logs_api_calls_at_the_level_matching_the_response(): void
    {
        $this->logger->logApiCall('proc-001', 'openai', '/v1/audio/transcriptions', 1.5, 200);
        $this->logger->logApiCall('proc-001', 'openai', '/v1/audio/transcriptions', 0.5, 500, 'Server error');

        Log::shouldHaveReceived('log')->withArgs(
            fn (string $level, string $message, array $context): bool => $level === 'info'
                && $context['service'] === 'openai'
                && $context['status_code'] === 200,
        )->once();
        Log::shouldHaveReceived('log')->withArgs(
            fn (string $level): bool => $level === 'error',
        )->once();
    }

    #[Test]
    public function it_logs_file_operations_with_metrics_and_errors(): void
    {
        $this->logger->logFileOperation('proc-001', 'upload', '/path/sermon.mp3', 1048576, 0.25);
        $this->logger->logFileOperation('proc-001', 'delete', '/path/file.mp3', null, null, 'Permission denied');

        Log::shouldHaveReceived('log')->withArgs(
            fn (string $level, string $message, array $context): bool => $level === 'info'
                && $context['operation'] === 'upload'
                && $context['file_size_bytes'] === 1048576
                && isset($context['file_size_human'])
                && $context['operation_time_ms'] > 0,
        )->once();
        Log::shouldHaveReceived('log')->withArgs(
            fn (string $level, string $message, array $context): bool => $level === 'error'
                && $context['error_message'] === 'Permission denied',
        )->once();
    }

    #[Test]
    public function it_logs_exceptions_with_sanitized_context(): void
    {
        $this->logger->logError('proc-001', 'transcription', new \RuntimeException('API down'));

        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message, array $context): bool => str_contains($message, 'transcription')
                && $context['processing_id'] === 'proc-001'
                && $context['exception_class'] === 'RuntimeException'
                && isset($context['stack_trace']),
        );
    }

    #[Test]
    public function it_logs_warnings_with_context(): void
    {
        $this->logger->logWarning('proc-001', 'video', 'Quality reduced', ['quality' => 'low']);

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => str_contains($message, 'video')
                && $context['warning_message'] === 'Quality reduced'
                && $context['context']['quality'] === 'low',
        );
    }
}
