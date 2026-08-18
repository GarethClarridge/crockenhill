<?php

declare(strict_types=1);

namespace App\Support;

use OpenAI\Exceptions\RateLimitException;
use Throwable;

/**
 * Recovers the rate-limit headers OpenAI actually sent, from wherever a 429 ends up in an exception
 * chain.
 *
 * The OoS parser arm runner wraps every source failure in a `RuntimeException` before it reaches the
 * caller, so a `RateLimitException` a 429 throws survives only as a `previous` cause several layers
 * down. Without walking the chain, a rate-limited arm fails with nothing more than "rate limit
 * exceeded" — indistinguishable from an exhausted requests-per-minute bucket, an exhausted
 * tokens-per-day budget, or transient flex-tier unavailability, three failure modes with three
 * different correct responses. The response headers name which one happened; this stops them being
 * discarded with the exception.
 */
class OpenAiRateLimitDiagnostics
{
    private const Headers = [
        'x-ratelimit-limit-requests',
        'x-ratelimit-remaining-requests',
        'x-ratelimit-reset-requests',
        'x-ratelimit-limit-tokens',
        'x-ratelimit-remaining-tokens',
        'x-ratelimit-reset-tokens',
        'retry-after',
    ];

    /**
     * Finds the first `RateLimitException` in `$exception`'s cause chain and reports its response
     * headers. Returns `null` when the chain carries no rate-limit failure, so a caller can tell
     * "not rate-limited" apart from "rate-limited but the provider sent no header detail".
     *
     * @return array<string, string>|null header name => value, or `(not present)` for a header
     *                                    OpenAI's response did not carry
     */
    public static function fromChain(Throwable $exception): ?array
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof RateLimitException) {
                return self::headers($current);
            }
        }

        return null;
    }

    /**
     * Read through PSR-7's `hasHeader()`/`getHeader()`, which the interface specifies as
     * case-insensitive, rather than by indexing `getHeaders()`.
     *
     * `getHeaders()` returns names in whatever case came off the wire, so an index lookup only
     * matches by luck. Probing lowercase and `STRTOUPPER` did not fix that — it misses the one
     * casing an HTTP/1.1 response is most likely to use, `X-RateLimit-Limit-Requests` — and would
     * have reported every header as absent on exactly the failure this class exists to diagnose.
     *
     * @return array<string, string>
     */
    private static function headers(RateLimitException $exception): array
    {
        $response = $exception->response;

        $found = [];

        foreach (self::Headers as $name) {
            $found[$name] = $response->hasHeader($name)
                ? implode(', ', $response->getHeader($name))
                : '(not present)';
        }

        return $found;
    }
}
