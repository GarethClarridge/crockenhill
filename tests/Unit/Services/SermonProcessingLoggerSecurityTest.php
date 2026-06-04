<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\ProcessingStatus;
use App\Services\Processing\SermonProcessingLogger;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonProcessingLoggerSecurityTest extends TestCase
{
    private SermonProcessingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new SermonProcessingLogger;
    }

    #[Test]
    public function it_sanitizes_filename_in_log_processing_start(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $message === 'Sermon processing started'
                    && $context['filename'] === 'malicious file.mp3'; // Control characters replaced by spaces and trimmed
            });

        $this->logger->logProcessingStart('proc-123', "malicious\nfile.mp3\r\t");
    }

    #[Test]
    public function it_sanitizes_error_message_and_step_in_log_processing_step(): void
    {
        Log::shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                return str_contains($message, 'test step')
                    && str_contains($message, 'failed status')
                    && $context['error_message'] === 'Failed with injection';
            });

        $this->logger->logProcessingStep('proc-123', "test\nstep", "failed\rstatus", [], "Failed\nwith\rinjection\t");
    }

    #[Test]
    public function it_sanitizes_error_message_and_service_in_log_api_call(): void
    {
        Log::shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                return str_contains($message, 'Malicious Service')
                    && $context['service'] === 'Malicious Service'
                    && $context['error_message'] === 'API error injection';
            });

        $this->logger->logApiCall('proc-123', "Malicious\nService", 'Endpoint', 1.0, 500, "API\nerror\rinjection\t");
    }

    #[Test]
    public function it_sanitizes_file_path_operation_and_error_message_in_log_file_operation(): void
    {
        Log::shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                return str_contains($message, 'Malicious Op')
                    && $context['operation'] === 'Malicious Op'
                    && $context['file_path'] === 'path to file.mp3'
                    && $context['error_message'] === 'File error injection';
            });

        $this->logger->logFileOperation('proc-123', "Malicious\nOp", "path\nto\rfile.mp3\t", null, null, "File\nerror\rinjection\t");
    }

    #[Test]
    public function it_sanitizes_error_message_in_log_processing_complete(): void
    {
        Log::shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                return $context['error_message'] === 'Final error injection';
            });

        $this->logger->logProcessingComplete('proc-123', ProcessingStatus::Failed, [], "Final\nerror\rinjection\t");
    }

    #[Test]
    public function it_sanitizes_exception_message_step_and_stack_trace_in_log_error(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return str_contains($message, 'test step')
                    && $context['step'] === 'test step'
                    && $context['exception_message'] === 'Exception injection'
                    && ! str_contains($context['stack_trace'], base_path()); // Stack trace should be sanitized
            });

        $exception = new \Exception("Exception\ninjection\r\t");
        $this->logger->logError('proc-123', "test\nstep", $exception);
    }

    #[Test]
    public function it_sanitizes_warning_message_and_step_in_log_warning(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return str_contains($message, 'test step')
                    && $context['step'] === 'test step'
                    && $context['warning_message'] === 'Warning injection';
            });

        $this->logger->logWarning('proc-123', "test\nstep", "Warning\ninjection\r\t");
    }

    #[Test]
    public function it_sanitizes_check_name_in_log_health_check(): void
    {
        Log::shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                return str_contains($message, 'malicious check')
                    && $context['health_check'] === 'malicious check';
            });

        $this->logger->logHealthCheck("malicious\ncheck", ['status' => 'healthy']);
    }
}
