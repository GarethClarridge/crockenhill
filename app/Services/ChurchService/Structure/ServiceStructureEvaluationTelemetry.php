<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Support\OpenAiUsageLogger;
use OpenAI\Responses\Chat\CreateResponse;

/**
 * Captures the token usage of one `detect()` call for `structure:evaluate`.
 *
 * The normal detection path never touches this — {@see OpenAiUsageLogger} already logs usage to
 * the application log for every call, real or evaluated. But a log line cannot be joined back onto
 * one manifest entry's report row, so item 6d's economic adoption gate (a candidate must cost 15%+
 * less than the incumbent) could not be measured from a `structure:evaluate` report alone. This
 * gives the evaluator the same call's usage a second time, addressed to the entry that produced it.
 *
 * Bound as a singleton so the one instance injected into the detector and the one injected into
 * the command are the same object.
 */
class ServiceStructureEvaluationTelemetry
{
    /** @var array{input_tokens: int, cached_input_tokens: int, output_tokens: int, reasoning_tokens: int, total_tokens: int}|null */
    private ?array $lastUsage = null;

    public function record(CreateResponse $response): void
    {
        $this->lastUsage = OpenAiUsageLogger::extractUsage($response);
    }

    /**
     * The usage recorded since the last call, or null when nothing was recorded — either because
     * the bound detector isn't OpenAI, or because the response carried no usage. Consuming clears
     * it, so a later entry cannot be attributed to an earlier one's call by mistake.
     *
     * @return array{input_tokens: int, cached_input_tokens: int, output_tokens: int, reasoning_tokens: int, total_tokens: int}|null
     */
    public function take(): ?array
    {
        $usage = $this->lastUsage;
        $this->lastUsage = null;

        return $usage;
    }
}
