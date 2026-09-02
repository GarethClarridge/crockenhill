<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Log;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\RateLimitException;
use OpenAI\Responses\Chat\CreateResponse;
use Throwable;

/**
 * Sends a chat completion on the flex tier and re-sends it on the default tier when OpenAI has no
 * flex capacity for it.
 *
 * Flex trades response time and *availability* for Batch API pricing, and OpenAI refuses a flex
 * request it cannot place with **HTTP 429** — the same status as a genuine rate limit.
 * `openai-php/client` raises every 429 as {@see RateLimitException}, whose message is the hardcoded
 * constant "Request rate limit has been exceeded." regardless of cause, so the two are
 * indistinguishable to anything that reads only `getMessage()`.
 *
 * On 2026-09-02 that cost a stratified learning pass every one of its clean completions: five runs
 * failed at structure detection and six banked empty fallback analysis, and the pass was recorded as
 * provider rate limiting for a day. It was not. The account held 99.98% of both its request and
 * token budgets throughout; `gpt-5.6-luna`'s flex pool was simply empty, and still refuses a
 * seven-token request while accepting the same request on the default tier. **Flex capacity is
 * per-model and moves independently of this application's own load, so neither pacing calls nor
 * widening a backoff can prevent one of these.** Changing tier is the only response that works.
 *
 * Falling back rather than abandoning flex keeps the discount for every call the pool can take, and
 * pays the standard rate only for the calls it refused. This class also logs the 429's real
 * `error.code` and rate-limit headers, so the next such failure is diagnosable from the log instead
 * of from a live probe.
 */
class OpenAiFlexFallback
{
    public const FlexTier = 'flex';

    public const DefaultTier = 'default';

    /**
     * OpenAI's code for "flex has no room right now", carried in the 429's body alongside
     * `type: resource_unavailable`. A 429 with any other code — `rate_limit_exceeded`,
     * `insufficient_quota` — is about this account rather than the tier, and switching tier would
     * neither help nor be honest, so those propagate untouched.
     */
    private const FlexUnavailableCode = 'flex_unavailable';

    /**
     * @param  array<string, mixed>  $payload  the chat payload, already normalised by {@see OpenAiChatPayload}
     * @param  callable(array<string, mixed>): CreateResponse  $send
     * @param  string  $operation  the usage-log operation label, so a 429 line names the caller
     */
    public static function send(array $payload, callable $send, string $operation): OpenAiTieredResponse
    {
        $requestedTier = is_string($payload['service_tier'] ?? null) ? $payload['service_tier'] : null;

        try {
            return new OpenAiTieredResponse($send($payload), $requestedTier, fellBackFromFlex: false);
        } catch (Throwable $exception) {
            self::logRefusal($exception, $payload, $operation);

            if ($requestedTier !== self::FlexTier || ! self::isFlexUnavailable($exception)) {
                throw $exception;
            }

            $payload['service_tier'] = self::DefaultTier;

            return new OpenAiTieredResponse($send($payload), self::DefaultTier, fellBackFromFlex: true);
        }
    }

    /**
     * Whether this failure is OpenAI declining a flex request for want of capacity, as opposed to
     * any other 429.
     *
     * Walks the cause chain because a 429 rarely surfaces as itself: callers wrap it, and the
     * sermon analysis service rethrows a generic message. This is the same reason
     * {@see OpenAiRateLimitDiagnostics::fromChain()} walks.
     */
    public static function isFlexUnavailable(Throwable $exception): bool
    {
        return self::errorCode($exception) === self::FlexUnavailableCode;
    }

    /**
     * The provider's own `error.code` for the first 429 in the cause chain, or null when the chain
     * carries no 429 or the body named no code.
     */
    public static function errorCode(Throwable $exception): ?string
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof ErrorException && $current->getStatusCode() === 429) {
                $code = $current->getErrorCode();

                return is_scalar($code) ? (string) $code : null;
            }

            if ($current instanceof RateLimitException) {
                /*
                 * RateLimitException keeps only the response, so the code has to come out of the
                 * body. Casting the stream to string rewinds a seekable body first, which the
                 * transporter has already read once by this point.
                 */
                $decoded = json_decode((string) $current->response->getBody(), true);

                $code = is_array($decoded) ? ($decoded['error']['code'] ?? null) : null;

                return is_scalar($code) ? (string) $code : null;
            }
        }

        return null;
    }

    /**
     * Records what the provider actually said, for any 429 — whether or not a fallback follows.
     *
     * Without this the only surviving evidence is the client's constant message. The headers
     * separate "this account is over its limit" (`remaining-requests`/`remaining-tokens` near zero)
     * from "this tier is full" (both near their limit), which is the distinction the whole 2026-09-02
     * misdiagnosis turned on.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function logRefusal(Throwable $exception, array $payload, string $operation): void
    {
        $headers = OpenAiRateLimitDiagnostics::fromChain($exception);

        if ($headers === null) {
            return;
        }

        $code = self::errorCode($exception);

        Log::warning('OpenAI refused a request with 429', [
            'operation' => $operation,
            'model' => is_scalar($payload['model'] ?? null) ? (string) $payload['model'] : null,
            'requested_service_tier' => $payload['service_tier'] ?? null,
            'error_code' => $code ?? '(none given)',
            'flex_unavailable' => $code === self::FlexUnavailableCode,
            ...$headers,
        ]);
    }
}
