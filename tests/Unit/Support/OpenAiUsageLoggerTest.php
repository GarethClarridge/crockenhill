<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\OpenAiUsageLogger;
use Illuminate\Support\Facades\Log;
use OpenAI\Responses\Chat\CreateResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OpenAiUsageLoggerTest extends TestCase
{
    #[Test]
    public function it_logs_chat_completion_token_usage_without_prompt_content(): void
    {
        config(['openai.service_tier' => 'flex']);
        Log::spy();

        $response = CreateResponse::fake([
            'model' => 'gpt-5.6-terra-2026-07-01',
            'usage' => [
                'prompt_tokens' => 1200,
                'completion_tokens' => 300,
                'total_tokens' => 1500,
                'prompt_tokens_details' => ['cached_tokens' => 400],
                'completion_tokens_details' => [
                    'reasoning_tokens' => 175,
                    'accepted_prediction_tokens' => 0,
                    'rejected_prediction_tokens' => 0,
                ],
            ],
        ]);

        OpenAiUsageLogger::log($response, 'sermon_analysis', 'gpt-5.6-terra', 'processing-1');

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $message === 'OpenAI chat completion usage'
                    && $context['operation'] === 'sermon_analysis'
                    && $context['processing_id'] === 'processing-1'
                    && $context['requested_model'] === 'gpt-5.6-terra'
                    && $context['response_model'] === 'gpt-5.6-terra-2026-07-01'
                    && $context['service_tier'] === 'flex'
                    && $context['input_tokens'] === 1200
                    && $context['cached_input_tokens'] === 400
                    && $context['output_tokens'] === 300
                    && $context['reasoning_tokens'] === 175
                    && $context['total_tokens'] === 1500;
            });
    }
}
