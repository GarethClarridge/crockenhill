<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Log;
use OpenAI\Responses\Chat\CreateResponse;

class OpenAiUsageLogger
{
    public static function log(
        CreateResponse $response,
        string $operation,
        string $requestedModel,
        ?string $processingId = null,
    ): void {
        if ($response->usage === null) {
            return;
        }

        $cachedInputTokens = $response->usage->promptTokensDetails === null
            ? 0
            : $response->usage->promptTokensDetails->cachedTokens;
        $reasoningTokens = $response->usage->completionTokensDetails === null
            ? 0
            : ($response->usage->completionTokensDetails->reasoningTokens ?? 0);

        Log::info('OpenAI chat completion usage', [
            'operation' => $operation,
            'processing_id' => $processingId,
            'requested_model' => $requestedModel,
            'response_model' => $response->model,
            'service_tier' => config('openai.service_tier'),
            'input_tokens' => $response->usage->promptTokens,
            'cached_input_tokens' => $cachedInputTokens,
            'output_tokens' => $response->usage->completionTokens,
            'reasoning_tokens' => $reasoningTokens,
            'total_tokens' => $response->usage->totalTokens,
        ]);
    }
}
