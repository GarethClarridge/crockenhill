<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Log;
use OpenAI\Responses\Chat\CreateResponse;

class OpenAiUsageLogger
{
    /**
     * Record one call's billed usage.
     *
     * `$requestedReasoningEffort` is the *configured* effort. Both it and the effort actually sent
     * are recorded, because they differ (`minimal` becomes `none` on GPT-5.4+) and a cost report
     * that quotes only the configured label misstates what was bought.
     *
     * `evaluation_arm` exists so a model/effort comparison can attribute spend. Two arms of the
     * same model differing only in reasoning effort produce identical `requested_model` lines, so
     * without an arm label their costs cannot be separated after the fact.
     */
    public static function log(
        CreateResponse $response,
        string $operation,
        string $requestedModel,
        ?string $processingId = null,
        ?string $requestedReasoningEffort = null,
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
            'evaluation_arm' => config('openai.evaluation_arm'),
            'requested_model' => $requestedModel,
            'response_model' => $response->model,
            'requested_reasoning_effort' => $requestedReasoningEffort,
            'effective_reasoning_effort' => $requestedReasoningEffort === null
                ? null
                : OpenAiChatPayload::effectiveReasoningEffort($requestedModel, $requestedReasoningEffort),
            'service_tier' => config('openai.service_tier'),
            'input_tokens' => $response->usage->promptTokens,
            'cached_input_tokens' => $cachedInputTokens,
            'output_tokens' => $response->usage->completionTokens,
            'reasoning_tokens' => $reasoningTokens,
            'total_tokens' => $response->usage->totalTokens,
        ]);
    }
}
