<?php

declare(strict_types=1);

namespace App\Services\Email;

use OpenAI\Responses\Chat\CreateResponse;
use RuntimeException;

/** In-memory, evaluation-only attribution for OoS extraction calls in one Artisan process. */
class OosParserEvaluationTelemetry
{
    private ?string $sourceKey = null;

    private ?string $returnedModel = null;

    /** @var list<array<string, mixed>> */
    private array $calls = [];

    public function beginRun(): void
    {
        $this->sourceKey = null;
        $this->calls = [];
        $this->returnedModel = null;
    }

    public function beginSource(string $sourceKey): void
    {
        if ($this->sourceKey !== null) {
            throw new RuntimeException("Evaluation telemetry is already recording {$this->sourceKey}.");
        }

        $this->sourceKey = $sourceKey;
    }

    public function record(CreateResponse $response, string $callRole, int $attempt, float $startedAt): void
    {
        if ($this->sourceKey === null) {
            return;
        }

        $usage = $response->usage;
        $this->calls[] = [
            'source_key' => $this->sourceKey,
            'call_role' => $callRole,
            'attempt' => $attempt,
            'response_model' => $response->model,
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'usage_missing' => $usage === null,
            'usage' => $usage === null ? null : [
                'input_tokens' => $usage->promptTokens,
                'output_tokens' => $usage->completionTokens,
                'total_tokens' => $usage->totalTokens,
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function finishSource(): array
    {
        if ($this->sourceKey === null) {
            throw new RuntimeException('Evaluation telemetry was not started for this source.');
        }

        $calls = $this->calls;
        $this->calls = [];
        $this->sourceKey = null;

        if ($calls === []) {
            throw new RuntimeException('The parser made no OpenAI calls for an evaluation source.');
        }

        foreach ($calls as $call) {
            $responseModel = $call['response_model'] ?? null;

            if (! is_string($responseModel) || $responseModel === '') {
                throw new RuntimeException('The provider did not return a model identifier.');
            }

            if ($this->returnedModel === null) {
                $this->returnedModel = $responseModel;
            }

            if ($responseModel !== $this->returnedModel) {
                throw new RuntimeException("The provider changed returned model from '{$this->returnedModel}' to '{$responseModel}' within one evaluation arm.");
            }
        }

        return $calls;
    }

    public function abandonSource(): void
    {
        $this->sourceKey = null;
        $this->calls = [];
    }

    public function returnedModel(): string
    {
        if ($this->returnedModel === null) {
            throw new RuntimeException('The evaluation arm produced no returned model identifier.');
        }

        return $this->returnedModel;
    }
}
