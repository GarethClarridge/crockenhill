<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Normalises an OpenAI chat-completions payload so a single call site stays valid whether it is
 * pointed at a classic chat model (gpt-4o, gpt-4.1, …) or a reasoning model (gpt-5, o-series).
 *
 * Reasoning models reject an explicit `temperature` (only the provider default is accepted) and
 * the legacy `max_tokens` field, and they accept a `reasoning_effort` knob whose hidden reasoning
 * tokens are billed against `max_completion_tokens`. Because every model in this application is
 * env-driven and swappable at runtime, normalising here keeps each call correct regardless of
 * which model the configuration currently resolves to.
 */
class OpenAiChatPayload
{
    /**
     * Reasoning families that require the parameter adjustments above: GPT-5 (`gpt-5`, `gpt-5-mini`,
     * `gpt-5-nano`, …) and the o-series (`o1`, `o3`, `o4-mini`, …). Classic models such as `gpt-4o`
     * and `gpt-4.1-nano` deliberately do not match and pass through untouched.
     */
    public static function isReasoningModel(string $model): bool
    {
        return (bool) preg_match('/^(gpt-5|o[1-9])/i', $model);
    }

    /**
     * Adjust a chat-completions payload for the model named in `$payload['model']`.
     *
     * Always migrates the deprecated `max_tokens` field to `max_completion_tokens`. For reasoning
     * models it additionally drops the unsupported `temperature` and attaches `reasoning_effort`
     * (unless the caller has already set one).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function forModel(array $payload, string $reasoningEffort = 'low'): array
    {
        if (empty($payload['service_tier'] ?? null)) {
            unset($payload['service_tier']);
        }

        if (array_key_exists('max_tokens', $payload)) {
            $payload['max_completion_tokens'] ??= $payload['max_tokens'];
            unset($payload['max_tokens']);
        }

        if (! self::isReasoningModel((string) ($payload['model'] ?? ''))) {
            return $payload;
        }

        unset($payload['temperature']);
        $payload['reasoning_effort'] ??= self::normaliseReasoningEffort(
            (string) ($payload['model'] ?? ''),
            $reasoningEffort,
        );

        return $payload;
    }

    private static function normaliseReasoningEffort(string $model, string $reasoningEffort): string
    {
        if ($reasoningEffort !== 'minimal') {
            return $reasoningEffort;
        }

        if (! preg_match('/^gpt-5\.(\d+)/i', $model, $matches)) {
            return $reasoningEffort;
        }

        return (int) $matches[1] >= 4 ? 'none' : $reasoningEffort;
    }
}
