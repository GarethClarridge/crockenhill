<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\OpenAiFlexFallback;
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

        OpenAiUsageLogger::log($response, 'sermon_analysis', 'gpt-5.6-terra', 'processing-1', 'low');

        Log::shouldHaveReceived('info')
            ->once()
            ->with('OpenAI chat completion usage', [
                'operation' => 'sermon_analysis',
                'processing_id' => 'processing-1',
                'evaluation_arm' => null,
                'requested_model' => 'gpt-5.6-terra',
                'response_model' => 'gpt-5.6-terra-2026-07-01',
                'requested_reasoning_effort' => 'low',
                'effective_reasoning_effort' => 'low',
                'service_tier' => 'flex',
                'input_tokens' => 1200,
                'cached_input_tokens' => 400,
                'output_tokens' => 300,
                'reasoning_tokens' => 175,
                'total_tokens' => 1500,
                'remaining_requests' => 2999,
                'request_limit' => 3000,
                'remaining_tokens' => 249989,
                'token_limit' => 250000,
            ]);
    }

    /**
     * The measurement that would have settled the 2026-09-02 "OpenAI rate limit" in one line.
     * OpenAI returns this headroom on every successful response at no extra cost, and a pass
     * running at its ceiling and a pass with 99.98% of its budget free are indistinguishable
     * without it.
     */
    #[Test]
    public function it_records_the_rate_limit_headroom_the_provider_returned(): void
    {
        Log::spy();

        $response = CreateResponse::fake([
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2, 'total_tokens' => 12],
        ]);

        OpenAiUsageLogger::log($response, 'service_structure', 'gpt-5.6-luna');

        Log::shouldHaveReceived('info')
            ->once()
            ->with('OpenAI chat completion usage', \Mockery::on(
                static fn (array $context): bool => $context['remaining_requests'] === 2999
                    && $context['request_limit'] === 3000
                    && $context['remaining_tokens'] === 249989
                    && $context['token_limit'] === 250000
            ));
    }

    /**
     * {@see OpenAiFlexFallback} can move a call to another tier mid-flight, so the
     * configured value stops describing the call. Reporting it anyway would put a field in the log
     * that states something the system did not do — the defect class that caused the misdiagnosis
     * this telemetry exists to prevent.
     */
    #[Test]
    public function it_records_the_tier_the_call_actually_ran_on_rather_than_the_configured_one(): void
    {
        config(['openai.service_tier' => 'flex']);
        Log::spy();

        $response = CreateResponse::fake([
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2, 'total_tokens' => 12],
        ]);

        OpenAiUsageLogger::log($response, 'service_structure', 'gpt-5.6-luna', 'processing-1', 'medium', 'default');

        Log::shouldHaveReceived('info')
            ->once()
            ->with('OpenAI chat completion usage', \Mockery::on(
                static fn (array $context): bool => $context['service_tier'] === 'default'
            ));
    }

    /**
     * Two arms of the same model that differ only in reasoning effort log an identical
     * `requested_model`. Without the arm label and the effort fields their costs cannot be
     * separated after the run, which is the whole point of measuring them apart.
     */
    #[Test]
    public function it_records_the_evaluation_arm_and_the_effort_actually_sent(): void
    {
        config(['openai.service_tier' => 'flex', 'openai.evaluation_arm' => 'arm-1-nano-minimal']);
        Log::spy();

        $response = CreateResponse::fake([
            'model' => 'gpt-5.4-nano-2026-05-01',
            'usage' => [
                'prompt_tokens' => 900,
                'completion_tokens' => 120,
                'total_tokens' => 1020,
            ],
        ]);

        OpenAiUsageLogger::log($response, 'oos_email_parsing', 'gpt-5.4-nano', requestedReasoningEffort: 'minimal');

        Log::shouldHaveReceived('info')
            ->once()
            ->with('OpenAI chat completion usage', \Mockery::on(
                static fn (array $context): bool => $context['evaluation_arm'] === 'arm-1-nano-minimal'
                    && $context['requested_reasoning_effort'] === 'minimal'
                    // GPT-5.4+ is sent `none` for a `minimal` request, so a report quoting the
                    // configured label alone would misdescribe what was billed.
                    && $context['effective_reasoning_effort'] === 'none'
            ));
    }
}
