<?php

declare(strict_types=1);

namespace App\Data;

use App\Support\OpenAiChatPayload;
use RuntimeException;

/**
 * The frozen arms of the OoS parser evaluation: two model-only arms, and two effort-only arms.
 *
 * The model-only pair answered a question it could not reach. Asked to parse the same email twice at
 * `effort=none`, nano returned a materially different order of service on 24 of 30 sources and Luna
 * on 19 of 30, against a 10% ceiling — so a non-inferiority test between two arms that each disagree
 * with *themselves* this often cannot say anything about either model. The prerequisite question is
 * reasoning effort, and it is logically prior to model choice.
 *
 * The effort arms therefore hold the **model** fixed at `gpt-5.4-nano` and vary only the effort.
 * Holding Luna instead would have been the wrong choice twice over: it re-decides the model question
 * that the closed evaluation deliberately left open (Luna is neither adopted nor rejected), and it
 * varies two things at once, so a passing arm could not say which change bought the stability. Nano
 * is also the configured production model, so a passing nano arm is a one-line config change rather
 * than a migration — and its recorded failure mode is the one more reasoning is most likely to fix:
 * `item_structure` drove 21 of its 24 disagreeing pairs (15 items on one call and 20 on the other),
 * where Luna's was mostly provenance line bindings, which are arbitrary tie-breaks rather than a
 * reasoning deficit.
 *
 * `high` is deliberately absent. `reasoning_token_headroom` carries a ceiling for it, but the
 * official guidance is to reach for a higher level only after a measured gain at a lower one.
 */
readonly class OosParserEvaluationArm
{
    /** @var array<string, array{model:string,reasoning_effort:string}> */
    private const Arms = [
        'baseline-nano-none' => ['model' => 'gpt-5.4-nano', 'reasoning_effort' => 'none'],
        'luna-none' => ['model' => 'gpt-5.6-luna', 'reasoning_effort' => 'none'],
        'nano-low' => ['model' => 'gpt-5.4-nano', 'reasoning_effort' => 'low'],
        'nano-medium' => ['model' => 'gpt-5.4-nano', 'reasoning_effort' => 'medium'],
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
