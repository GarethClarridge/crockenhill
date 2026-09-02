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
     *
     * `$serviceTier` is the tier the call actually ran on. It defaults to the configured tier for
     * callers that never change tier, but {@see OpenAiFlexFallback} does change it, and a line that
     * reported the configured value after a fallback would misstate what was bought.
     */
    public static function log(
        CreateResponse $response,
        string $operation,
        string $requestedModel,
        ?string $processingId = null,
        ?string $requestedReasoningEffort = null,
        ?string $serviceTier = null,
    ): void {
        $usage = self::extractUsage($response);

        if ($usage === null) {
            return;
        }

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
            'service_tier' => $serviceTier ?? config('openai.service_tier'),
            ...$usage,
            ...self::headroom($response),
        ]);
    }

    /**
     * The rate-limit headroom OpenAI returned with this successful call.
     *
     * These headers arrive on every response and cost nothing extra to read, and they are the
     * measurement that would have settled the 2026-09-02 "rate limit" in one line: a pass running
     * near its ceiling and a pass with 99.98% of its budget free look identical without them.
     *
     * @return array{remaining_requests: int|null, request_limit: int|null, remaining_tokens: int|null, token_limit: int|null}
     */
    private static function headroom(CreateResponse $response): array
    {
        $meta = $response->meta();

        return [
            'remaining_requests' => $meta->requestLimit?->remaining,
            'request_limit' => $meta->requestLimit?->limit,
            'remaining_tokens' => $meta->tokenLimit?->remaining,
            'token_limit' => $meta->tokenLimit?->limit,
        ];
    }

    /**
     * The token-usage shape shared by the log line above and any caller that
     * wants to cost a call itself (a `structure:evaluate`/`sermons:evaluate-analysis`
     * report, for instance) without re-deriving it from the raw response.
     *
     * @return array{input_tokens: int, cached_input_tokens: int, output_tokens: int, reasoning_tokens: int, total_tokens: int}|null
     */
    public static function extractUsage(CreateResponse $response): ?array
    {
        if ($response->usage === null) {
            return null;
        }

        return [
            'input_tokens' => $response->usage->promptTokens,
            'cached_input_tokens' => $response->usage->promptTokensDetails === null
                ? 0
                : $response->usage->promptTokensDetails->cachedTokens,
            'output_tokens' => $response->usage->completionTokens ?? 0,
            'reasoning_tokens' => $response->usage->completionTokensDetails === null
                ? 0
                : ($response->usage->completionTokensDetails->reasoningTokens ?? 0),
            'total_tokens' => $response->usage->totalTokens,
        ];
    }
}
