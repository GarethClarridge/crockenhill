<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\OosSemanticResponseTruncatedException;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\RateLimitException;
use OpenAI\Exceptions\ServerException;
use OpenAI\Exceptions\TransporterException;
use Throwable;

/**
 * The single rule for "ask again later" versus "this answer is no good".
 *
 * The distinction is load-bearing and the two callers had drifted apart on it. `OpenAiOosSemanticAnnotator`
 * and `OpenAiOosSemanticRepairer` each carried a verbatim copy of the classifier, while the legacy
 * `OpenAiOosEmailItemExtractor` retried under `catch (RuntimeException)` — and the OpenAI client
 * raises every HTTP failure as {@see ErrorException}, which extends `Exception`, not
 * `RuntimeException`. A rate limit therefore aborted a legacy parse outright without one retry.
 *
 * Backoff is part of the same rule rather than a caller's choice, because getting the classification
 * right and the wait wrong fixes nothing: the semantic path already classified 429 correctly and
 * still failed, since its 100/500/1000ms curve exhausted three attempts in 1.6 seconds. A rate-limit
 * window is measured in seconds, so a rate limit gets a seconds-scale wait while a dropped
 * connection or 5xx keeps its prompt retry.
 *
 * Discovered when a luna evaluation arm lost 32 minutes of completed work to a 429 on its final
 * source; see the plan's §9.13.
 */
class OpenAiTransientFailure
{
    /**
     * Waits for a rate limit, in milliseconds, when the provider names no `Retry-After`.
     *
     * Seconds-scale and widening, because the useful response to "too many requests" is to stop
     * making them for a while. Capped by {@see self::MaxDelayMs} so a long curve cannot outlast the
     * caller's own timeout budget.
     */
    private const RateLimitDelaysMs = [2_000, 8_000, 20_000];

    /** Prompt retries for a dropped connection, a 5xx or a truncated response. */
    private const TransportDelaysMs = [100, 500, 1_000];

    private const MaxDelayMs = 60_000;

    /**
     * Whether asking again could plausibly succeed with the request unchanged.
     *
     * A `RuntimeException` from the parsers means the model returned something unusable, which is a
     * *semantic* retry the callers budget separately — it is deliberately not transient here.
     */
    public static function isTransient(Throwable $exception): bool
    {
        if ($exception instanceof TransporterException
            || $exception instanceof ServerException
            || $exception instanceof RateLimitException
            || $exception instanceof OosSemanticResponseTruncatedException) {
            return true;
        }

        return $exception instanceof ErrorException
            && in_array($exception->getStatusCode(), [429, 500, 502, 503, 504], true);
    }

    /** Whether the provider refused because too many requests were made, rather than for any other reason. */
    public static function isRateLimit(Throwable $exception): bool
    {
        if ($exception instanceof RateLimitException) {
            return true;
        }

        return $exception instanceof ErrorException && $exception->getStatusCode() === 429;
    }

    /**
     * How long to wait before attempt number `$attempt + 1`.
     *
     * A provider-supplied `Retry-After` always wins: it is the only figure that reflects the actual
     * window rather than a guess about it. The header is read from the response the client attached
     * to the exception, and both its documented forms are accepted — delay-seconds, and an HTTP date.
     */
    public static function delayMs(Throwable $exception, int $attempt): int
    {
        $retryAfter = self::retryAfterMs($exception);

        if ($retryAfter !== null) {
            return min($retryAfter, self::MaxDelayMs);
        }

        $curve = self::isRateLimit($exception) ? self::RateLimitDelaysMs : self::TransportDelaysMs;
        $index = max(0, min($attempt - 1, count($curve) - 1));

        return min($curve[$index], self::MaxDelayMs);
    }

    /** The provider's own `Retry-After`, in milliseconds, when it supplied a usable one. */
    private static function retryAfterMs(Throwable $exception): ?int
    {
        if (! $exception instanceof ErrorException) {
            return null;
        }

        $header = $exception->response->getHeaderLine('Retry-After');

        if ($header === '') {
            return null;
        }

        if (is_numeric($header)) {
            return max(0, (int) round(((float) $header) * 1000));
        }

        $timestamp = strtotime($header);

        if ($timestamp === false) {
            return null;
        }

        return max(0, ($timestamp - time()) * 1000);
    }
}
