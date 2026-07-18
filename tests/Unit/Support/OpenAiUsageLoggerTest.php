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
            ->with('OpenAI chat completion usage', [
                'operation' => 'sermon_analysis',
                'processing_id' => 'processing-1',
                'requested_model' => 'gpt-5.6-terra',
                'response_model' => 'gpt-5.6-terra-2026-07-01',
                'service_tier' => 'flex',
                'input_tokens' => 1200,
                'cached_input_tokens' => 400,
                'output_tokens' => 300,
                'reasoning_tokens' => 175,
                'total_tokens' => 1500,
            ]);
    }
}
