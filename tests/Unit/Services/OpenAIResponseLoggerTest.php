<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\OpenAIResponseLogger;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OpenAIResponseLoggerTest extends TestCase
{
    #[Test]
    public function it_logs_response_analysis_for_strings(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->with('OpenAI API response type analysis', [
                'processing_id' => 'test-id',
                'attempt' => 1,
                'response_data_type' => 'string',
                'response_type_hint' => 'test-hint',
                'response_content_preview' => 'test content',
                'response_length' => 12,
                'is_json' => 'no',
            ]);

        OpenAIResponseLogger::logResponse('test-id', 1, 'test content', 'test-hint');
    }

    #[Test]
    public function it_detects_likely_errors_in_string_responses(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->with('OpenAI API response type analysis', \Mockery::on(function ($data) {
                return $data['likely_html_error'] === true &&
                       $data['likely_auth_error'] === true &&
                       $data['likely_rate_limit'] === true;
            }));

        OpenAIResponseLogger::logResponse('test-id', 1, '<html> unauthorized rate limit </html>');
    }

    #[Test]
    public function it_detects_json_in_string_responses(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->with('OpenAI API response type analysis', \Mockery::on(function ($data) {
                return $data['is_json'] === 'yes';
            }));

        OpenAIResponseLogger::logResponse('test-id', 1, json_encode(['foo' => 'bar']));
    }

    #[Test]
    public function it_truncates_long_string_responses(): void
    {
        $longString = str_repeat('a', 600);

        Log::shouldReceive('warning')
            ->once()
            ->with('OpenAI API response type analysis', \Mockery::on(function ($data) {
                return strlen($data['response_content_preview']) === 500 &&
                       $data['response_length'] === 600;
            }));

        OpenAIResponseLogger::logResponse('test-id', 1, $longString);
    }

    #[Test]
    public function it_logs_response_analysis_for_arrays(): void
    {
        $responseData = ['foo' => 'bar', 'baz' => 'qux'];

        Log::shouldReceive('warning')
            ->once()
            ->with('OpenAI API response type analysis', [
                'processing_id' => 'test-id',
                'attempt' => 1,
                'response_data_type' => 'array',
                'response_type_hint' => null,
                'response_keys' => ['foo', 'baz'],
                'response_structure_valid' => true,
            ]);

        OpenAIResponseLogger::logResponse('test-id', 1, $responseData);
    }

    #[Test]
    public function it_logs_transport_errors_without_body(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->with('OpenAI API transport layer error', [
                'processing_id' => 'test-id',
                'attempt' => 1,
                'error_message' => 'Network failure',
                'status_code' => 500,
            ]);

        OpenAIResponseLogger::logTransportError('test-id', 1, 'Network failure', 500);
    }

    #[Test]
    public function it_logs_transport_errors_with_non_json_body(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->with('OpenAI API transport layer error', \Mockery::on(function ($data) {
                return $data['response_body_preview'] === 'Not found' &&
                       $data['response_body_length'] === 9 &&
                       $data['response_is_not_json'] === true;
            }));

        OpenAIResponseLogger::logTransportError('test-id', 1, 'Error', 404, 'Not found');
    }

    #[Test]
    public function it_logs_transport_errors_with_json_body(): void
    {
        $jsonBody = json_encode(['error' => ['message' => 'Invalid key']]);

        Log::shouldReceive('error')
            ->once()
            ->with('OpenAI API transport layer error', \Mockery::on(function ($data) {
                return $data['error_response_json'] === ['error' => ['message' => 'Invalid key']];
            }));

        OpenAIResponseLogger::logTransportError('test-id', 1, 'Error', 401, $jsonBody);
    }

    #[Test]
    public function it_truncates_long_transport_error_bodies(): void
    {
        $longBody = str_repeat('b', 600);

        Log::shouldReceive('error')
            ->once()
            ->with('OpenAI API transport layer error', \Mockery::on(function ($data) {
                return strlen($data['response_body_preview']) === 500 &&
                       $data['response_body_length'] === 600;
            }));

        OpenAIResponseLogger::logTransportError('test-id', 1, 'Error', 500, $longBody);
    }
}
