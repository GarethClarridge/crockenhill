<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\OpenAIResponseLogger;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OpenAIResponseLoggerSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Log::spy();
    }

    #[Test]
    public function it_sanitizes_processing_id_and_response_preview_in_log_response(): void
    {
        OpenAIResponseLogger::logResponse("proc-123\n", 1, "malicious\nresponse\r\t");

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $context['processing_id'] === 'proc-123'
                    && $context['response_content_preview'] === 'malicious response';
            });
    }

    #[Test]
    public function it_sanitizes_processing_id_and_error_message_and_body_preview_in_log_transport_error(): void
    {
        OpenAIResponseLogger::logTransportError("proc-123\r", 1, "transport\nerror\t", 500, "error\nbody\r");

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $context['processing_id'] === 'proc-123'
                    && $context['error_message'] === 'transport error'
                    && $context['response_body_preview'] === 'error body';
            });
    }
}
