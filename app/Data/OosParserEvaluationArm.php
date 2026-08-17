<?php

declare(strict_types=1);

namespace App\Data;

use App\Support\OpenAiChatPayload;
use RuntimeException;

/** The two frozen, model-only arms of the 2026-08-17 OoS parser evaluation. */
readonly class OosParserEvaluationArm
{
    /** @var array<string, array{model:string,reasoning_effort:string}> */
    private const Arms = [
        'baseline-nano-none' => ['model' => 'gpt-5.4-nano', 'reasoning_effort' => 'none'],
        'luna-none' => ['model' => 'gpt-5.6-luna', 'reasoning_effort' => 'none'],
    ];

    private function __construct(
        public string $name,
        public string $model,
        public string $configuredReasoningEffort,
        public string $effectiveReasoningEffort,
    ) {}

    public static function fromName(string $name): self
    {
        $definition = self::Arms[$name] ?? null;

        if ($definition === null) {
            throw new RuntimeException("Unknown OoS parser evaluation arm '{$name}'.");
        }

        $effectiveEffort = OpenAiChatPayload::effectiveReasoningEffort(
            $definition['model'],
            $definition['reasoning_effort'],
        );

        if ($effectiveEffort === null) {
            throw new RuntimeException("OoS parser evaluation arm '{$name}' must use a reasoning model.");
        }

        return new self($name, $definition['model'], $definition['reasoning_effort'], $effectiveEffort);
    }

    public function apply(): void
    {
        config([
            'service-tracking.email_parsing.model' => $this->model,
            'service-tracking.email_parsing.reasoning_effort' => $this->configuredReasoningEffort,
            'openai.evaluation_arm' => $this->name,
        ]);
    }

    /** @return array{model:string,configured_reasoning_effort:string,effective_reasoning_effort:string} */
    public function resolvedConfiguration(): array
    {
        $model = config('service-tracking.email_parsing.model');
        $effort = config('service-tracking.email_parsing.reasoning_effort');

        if (! is_string($model) || ! is_string($effort)) {
            throw new RuntimeException("OoS parser evaluation arm '{$this->name}' resolved an invalid model configuration.");
        }

        $effectiveEffort = OpenAiChatPayload::effectiveReasoningEffort($model, $effort);

        if ($model !== $this->model || $effort !== $this->configuredReasoningEffort || $effectiveEffort !== $this->effectiveReasoningEffort) {
            throw new RuntimeException("OoS parser evaluation arm '{$this->name}' does not match its frozen model configuration.");
        }

        return [
            'model' => $model,
            'configured_reasoning_effort' => $effort,
            'effective_reasoning_effort' => $effectiveEffort,
        ];
    }
}
