<?php

declare(strict_types=1);

namespace App\Support;

use OpenAI\Responses\Chat\CreateResponse;

/**
 * A completion plus the service tier that actually produced it.
 *
 * {@see OpenAiFlexFallback} can send a request on a different tier from the one the caller asked
 * for, so the configured tier stops describing the call the moment a fallback happens. A usage line
 * that reports `config('openai.service_tier')` after a fallback states something the system did not
 * do — the same class of defect as the client's hardcoded "Request rate limit has been exceeded."
 * that cost a day of misdiagnosis on 2026-09-02. Carrying the tier back with the response is the
 * cheapest way to keep the telemetry true.
 */
final readonly class OpenAiTieredResponse
{
    public function __construct(
        public CreateResponse $response,
        public ?string $serviceTier,
        public bool $fellBackFromFlex,
    ) {}
}
