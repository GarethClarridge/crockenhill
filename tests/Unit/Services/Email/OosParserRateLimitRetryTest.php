<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Support\OpenAiTransientFailure;
use GuzzleHttp\Psr7\Response as PsrResponse;
use OpenAI\Exceptions\ErrorException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A rate limit is a "come back later" answer, not a bad answer.
 *
 * The semantic path classified a 429 correctly but backed off on a 100/500/1000ms curve, exhausting
 * three attempts in 1.6 seconds, which no rate-limit window respects. (The legacy extractor's own
 * defect — retrying under `catch (RuntimeException)` while the OpenAI client raises a 429 as
 * `ErrorException extends Exception`, so a rate limit aborted the parse outright — went with that
 * extractor when Delivery 7 deleted it.)
 *
 * Both were found by a luna evaluation arm losing 32 minutes of completed work to a 429 on its final
 * source (plan §9.13).
 */
class OosParserRateLimitRetryTest extends TestCase
{
    #[Test]
    public function a_rate_limit_waits_in_seconds_rather_than_milliseconds(): void
    {
        $delay = OpenAiTransientFailure::delayMs($this->rateLimitException(), attempt: 1);

        $this->assertGreaterThanOrEqual(
            1000,
            $delay,
            'A sub-second backoff cannot clear a rate-limit window; 100/500/1000ms exhausted three attempts in 1.6s.',
        );
    }

    #[Test]
    public function an_explicit_retry_after_header_is_honoured_over_the_default_curve(): void
    {
        $exception = new ErrorException(
            ['message' => 'Request rate limit has been exceeded.', 'type' => 'rate_limit_error'],
            new PsrResponse(429, ['Retry-After' => '7']),
        );

        $this->assertSame(7000, OpenAiTransientFailure::delayMs($exception, attempt: 1));
    }

    #[Test]
    public function a_connection_blip_still_retries_quickly(): void
    {
        $exception = new ErrorException(
            ['message' => 'Bad gateway', 'type' => 'server_error'],
            new PsrResponse(502),
        );

        $this->assertLessThan(
            1000,
            OpenAiTransientFailure::delayMs($exception, attempt: 1),
            'Only rate limits need a long wait; a 5xx or dropped connection should retry promptly.',
        );
    }

    #[Test]
    public function an_unusable_response_is_not_treated_as_transient(): void
    {
        $this->assertFalse(OpenAiTransientFailure::isTransient(new \RuntimeException('Model returned no JSON.')));
        $this->assertTrue(OpenAiTransientFailure::isTransient($this->rateLimitException()));
    }

    private function rateLimitException(): ErrorException
    {
        return new ErrorException(
            ['message' => 'Request rate limit has been exceeded.', 'type' => 'rate_limit_error'],
            new PsrResponse(429),
        );
    }
}
