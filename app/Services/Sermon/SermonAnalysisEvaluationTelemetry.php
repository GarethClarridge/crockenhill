<?php

declare(strict_types=1);

namespace App\Services\Sermon;

use App\Support\CanonicalJson;
use OpenAI\Responses\Chat\CreateResponse;
use RuntimeException;
use Throwable;

/**
 * Captures one report-only sermon-analysis call for the bounded historic evaluation.
 *
 * The normal sermon-analysis path never starts a capture. The evaluator starts one around each
 * call, allowing the existing analysis service to remain the sole implementation while preserving
 * the raw response and usage needed for blinded review and costing.
 */
class SermonAnalysisEvaluationTelemetry
{
    /** @var array<string, mixed>|null */
    private ?array $current = null;

    /**
     * @param  string  $label  Human-readable banked input label
     * @param  string  $inputHash  SHA-256 of the exact banked transcript bytes
     */
    public function begin(string $label, string $inputHash): void
    {
        if ($this->current !== null) {
            throw new RuntimeException('Sermon-analysis evaluation telemetry is already capturing a call.');
        }

        $this->current = [
            'label' => $label,
            'input_sha256' => $inputHash,
            'request' => null,
            'response' => null,
            'validation' => null,
            'failure' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload  The exact normalised chat payload sent to OpenAI
     */
    public function recordRequest(
        string $processingId,
        array $payload,
        string $configuredReasoningEffort,
    ): void {
        if ($this->current === null) {
            return;
        }

        /** @var list<array<string, mixed>> $messages */
        $messages = is_array($payload['messages'] ?? null) ? $payload['messages'] : [];
        $systemMessage = is_array($messages[0] ?? null) ? $messages[0] : [];
        $userMessage = is_array($messages[1] ?? null) ? $messages[1] : [];

        $this->current['request'] = [
            'processing_id' => $processingId,
            'requested_model' => (string) ($payload['model'] ?? ''),
            'configured_reasoning_effort' => $configuredReasoningEffort,
            'effective_reasoning_effort' => is_string($payload['reasoning_effort'] ?? null)
                ? $payload['reasoning_effort']
                : null,
            'service_tier' => $payload['service_tier'] ?? null,
            'prompt_sha256' => CanonicalJson::hash($userMessage['content'] ?? null),
            'surface_sha256' => CanonicalJson::hash($systemMessage['content'] ?? null),
            'request_sha256' => CanonicalJson::hash($payload),
        ];
    }

    public function recordResponse(CreateResponse $response, float $latencySeconds): void
    {
        if ($this->current === null) {
            return;
        }

        $choice = $response->choices[0] ?? null;
        $finishReason = $choice?->finishReason;
        $usage = $response->usage;

        $this->current['response'] = [
            'raw_output' => $choice?->message->content,
            'response_model' => $response->model,
            'finish_reason' => $finishReason,
            'truncated' => $finishReason === 'length',
            'latency_ms' => (int) round($latencySeconds * 1000),
            'usage_missing' => $usage === null,
            'usage' => $usage === null ? null : [
                'input_tokens' => $usage->promptTokens,
                'cached_input_tokens' => $usage->promptTokensDetails === null
                    ? 0
                    : $usage->promptTokensDetails->cachedTokens,
                'output_tokens' => $usage->completionTokens ?? 0,
                'reasoning_tokens' => $usage->completionTokensDetails === null
                    ? 0
                    : ($usage->completionTokensDetails->reasoningTokens ?? 0),
                'total_tokens' => $usage->totalTokens,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $validatedData
     */
    public function recordValidation(array $validatedData): void
    {
        if ($this->current === null) {
            return;
        }

        unset($validatedData['transcript']);

        $this->current['validation'] = [
            'status' => 'passed',
            'normalised_output' => $validatedData,
        ];
    }

    public function recordFailure(Throwable $exception): void
    {
        if ($this->current === null) {
            return;
        }

        $this->current['failure'] = [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function finish(): array
    {
        if ($this->current === null) {
            throw new RuntimeException('Sermon-analysis evaluation telemetry was not started.');
        }

        $capture = $this->current;
        $this->current = null;

        return $capture;
    }
}
