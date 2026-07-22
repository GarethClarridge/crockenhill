<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\OpenAIResponseLogger;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OpenAIResponseLoggerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Log::spy();
    }

    #[Test]
    public function it_logs_response_analysis_for_strings(): void
    {
        OpenAIResponseLogger::logResponse('test-id', 1, 'test content', 'test-hint');

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $message === 'OpenAI API response type analysis'
                    && $context['processing_id'] === 'test-id'
                    && $context['attempt'] === 1
                    && $context['response_data_type'] === 'string'
                    && $context['response_type_hint'] === 'test-hint'
                    && $context['response_content_preview'] === 'test content'
                    && $context['response_length'] === 12
                    && $context['is_json'] === 'no';
            });
    }

    #[Test]
    public function it_detects_likely_errors_in_string_responses(): void
    {
        OpenAIResponseLogger::logResponse('test-id', 1, '<html> unauthorized rate limit </html>');

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('OpenAI API response type analysis', \Mockery::on(function ($data) {
                return $data['likely_html_error'] === true &&
                       $data['likely_auth_error'] === true &&
                       $data['likely_rate_limit'] === true;
            }));
    }

    #[Test]
    public function it_detects_json_in_string_responses(): void
    {
        OpenAIResponseLogger::logResponse('test-id', 1, json_encode(['foo' => 'bar']));

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('OpenAI API response type analysis', \Mockery::on(function ($data) {
                return $data['is_json'] === 'yes';
            }));
    }

    #[Test]
    public function it_truncates_long_string_responses(): void
    {
        $longString = str_repeat('a', 600);

        OpenAIResponseLogger::logResponse('test-id', 1, $longString);

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('OpenAI API response type analysis', \Mockery::on(function ($data) {
                return strlen($data['response_content_preview']) === 500 &&
                       $data['response_length'] === 600;
            }));
    }

    #[Test]
    public function it_logs_response_analysis_for_arrays(): void
    {
        $responseData = ['foo' => 'bar', 'baz' => 'qux'];

        OpenAIResponseLogger::logResponse('test-id', 1, $responseData);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $message === 'OpenAI API response type analysis'
                    && $context['processing_id'] === 'test-id'
                    && $context['attempt'] === 1
                    && $context['response_data_type'] === 'array'
                    && $context['response_type_hint'] === null
                    && $context['response_keys'] === ['foo', 'baz']
                    && $context['response_structure_valid'] === true;
            });
    }

    #[Test]
    public function it_logs_transport_errors_without_body(): void
    {
        OpenAIResponseLogger::logTransportError('test-id', 1, 'Network failure', 500);

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $message === 'OpenAI API transport layer error'
                    && $context['processing_id'] === 'test-id'
                    && $context['attempt'] === 1
                    && $context['error_message'] === 'Network failure'
                    && $context['status_code'] === 500;
            });
    }

    #[Test]
    public function it_logs_transport_errors_with_non_json_body(): void
    {
        OpenAIResponseLogger::logTransportError('test-id', 1, 'Error', 404, 'Not found');

        Log::shouldHaveReceived('error')
            ->once()
            ->with('OpenAI API transport layer error', \Mockery::on(function ($data) {
                return $data['response_body_preview'] === 'Not found' &&
                       $data['response_body_length'] === 9 &&
                       $data['response_is_not_json'] === true;
            }));
    }

    #[Test]
    public function it_logs_transport_errors_with_json_body(): void
    {
        $jsonBody = json_encode(['error' => ['message' => 'Invalid key']]);

        OpenAIResponseLogger::logTransportError('test-id', 1, 'Error', 401, $jsonBody);

        Log::shouldHaveReceived('error')
            ->once()
            ->with('OpenAI API transport layer error', \Mockery::on(function ($data) {
                return $data['error_response_json'] === ['error' => ['message' => 'Invalid key']];
            }));
    }

    #[Test]
    public function it_truncates_long_transport_error_bodies(): void
    {
        $longBody = str_repeat('b', 600);

        OpenAIResponseLogger::logTransportError('test-id', 1, 'Error', 500, $longBody);

        Log::shouldHaveReceived('error')
            ->once()
            ->with('OpenAI API transport layer error', \Mockery::on(function ($data) {
                return strlen($data['response_body_preview']) === 500 &&
                       $data['response_body_length'] === 600;
            }));
    }
}
