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

        Log::spy();

        $this->logger = new SermonProcessingLogger;
    }

    #[Test]
    public function it_sanitizes_filename_in_log_processing_start(): void
    {
        $this->logger->logProcessingStart('proc-123', "malicious\nfile.mp3\r\t");

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $message === 'Sermon processing started'
                    && $context['filename'] === 'malicious file.mp3'; // Control characters replaced by spaces and trimmed
            });
    }

    #[Test]
    public function it_sanitizes_error_message_and_step_in_log_processing_step(): void
    {
        $this->logger->logProcessingStep('proc-123', "test\nstep", "failed\rstatus", [], "Failed\nwith\rinjection\t");

        Log::shouldHaveReceived('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                return str_contains($message, 'test step')
                    && str_contains($message, 'failed status')
                    && $context['error_message'] === 'Failed with injection';
            });
    }

    #[Test]
    public function it_sanitizes_error_message_and_service_in_log_api_call(): void
    {
        $this->logger->logApiCall('proc-123', "Malicious\nService", 'Endpoint', 1.0, 500, "API\nerror\rinjection\t");

        Log::shouldHaveReceived('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                return str_contains($message, 'Malicious Service')
                    && $context['service'] === 'Malicious Service'
                    && $context['error_message'] === 'API error injection';
            });
    }

    #[Test]
    public function it_sanitizes_file_path_operation_and_error_message_in_log_file_operation(): void
    {
        $this->logger->logFileOperation('proc-123', "Malicious\nOp", "path\nto\rfile.mp3\t", null, null, "File\nerror\rinjection\t");

        Log::shouldHaveReceived('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                return str_contains($message, 'Malicious Op')
                    && $context['operation'] === 'Malicious Op'
                    && $context['file_path'] === 'path to file.mp3'
                    && $context['error_message'] === 'File error injection';
            });
    }

    #[Test]
    public function it_sanitizes_error_message_in_log_processing_complete(): void
    {
        $this->logger->logProcessingComplete('proc-123', ProcessingStatus::Failed, [], "Final\nerror\rinjection\t");

        Log::shouldHaveReceived('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                return $context['error_message'] === 'Final error injection';
            });
    }

    #[Test]
    public function it_sanitizes_exception_message_step_and_stack_trace_in_log_error(): void
    {
        $exception = new \Exception("Exception\ninjection\r\t");
        $this->logger->logError('proc-123', "test\nstep", $exception);

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return str_contains($message, 'test step')
                    && $context['step'] === 'test step'
                    && $context['exception_message'] === 'Exception injection'
                    && ! str_contains($context['stack_trace'], base_path()); // Stack trace should be sanitized
            });
    }

    #[Test]
    public function it_sanitizes_warning_message_and_step_in_log_warning(): void
    {
        $this->logger->logWarning('proc-123', "test\nstep", "Warning\ninjection\r\t");

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return str_contains($message, 'test step')
                    && $context['step'] === 'test step'
                    && $context['warning_message'] === 'Warning injection';
            });
    }

    #[Test]
    public function it_sanitizes_check_name_in_log_health_check(): void
    {
        $this->logger->logHealthCheck("malicious\ncheck", ['status' => 'healthy']);

        Log::shouldHaveReceived('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                return str_contains($message, 'malicious check')
                    && $context['health_check'] === 'malicious check';
            });
    }
}
