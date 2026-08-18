<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\OpenAiRateLimitDiagnostics;
use GuzzleHttp\Psr7\Response;
use OpenAI\Exceptions\RateLimitException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class OpenAiRateLimitDiagnosticsTest extends TestCase
{
    #[Test]
    public function it_returns_null_when_the_chain_carries_no_rate_limit_exception(): void
    {
        $exception = new RuntimeException('Evaluation arm is incomplete: some other failure', previous: new RuntimeException('root cause'));

        $this->assertNull(OpenAiRateLimitDiagnostics::fromChain($exception));
    }

    #[Test]
    public function it_reads_the_rate_limit_headers_off_the_wrapped_response(): void
    {
        $response = new Response(429, [
            'x-ratelimit-limit-requests' => '500',
            'x-ratelimit-remaining-requests' => '499',
            'x-ratelimit-limit-tokens' => '200000',
            'x-ratelimit-remaining-tokens' => '0',
            'retry-after' => '3600',
        ]);

        $rateLimit = new RateLimitException($response);
        $wrapped = new RuntimeException("Evaluation arm 'luna-none' is incomplete: source-1 failed: {$rateLimit->getMessage()}", previous: $rateLimit);

        $headers = OpenAiRateLimitDiagnostics::fromChain($wrapped);

        $this->assertNotNull($headers);
        $this->assertSame('500', $headers['x-ratelimit-limit-requests']);
        $this->assertSame('499', $headers['x-ratelimit-remaining-requests']);
        $this->assertSame('200000', $headers['x-ratelimit-limit-tokens']);
        $this->assertSame('0', $headers['x-ratelimit-remaining-tokens']);
        $this->assertSame('3600', $headers['retry-after']);
        $this->assertSame('(not present)', $headers['x-ratelimit-reset-requests']);
        $this->assertSame('(not present)', $headers['x-ratelimit-reset-tokens']);
    }

    /**
     * HTTP header names are case-insensitive on the wire and PSR-7 preserves whatever casing the
     * server sent, so a lookup that indexes `getHeaders()` matches only by luck. Reading the same
     * response back in conventional `X-RateLimit-…` casing is the case a real OpenAI 429 is most
     * likely to arrive in, and the one an earlier lowercase-plus-STRTOUPPER probe silently missed —
     * reporting every header absent on exactly the failure this class exists to diagnose.
     */
    #[Test]
    public function it_reads_the_headers_whatever_case_the_response_carries_them_in(): void
    {
        $response = new Response(429, [
            'X-RateLimit-Limit-Requests' => '500',
            'X-RateLimit-Remaining-Tokens' => '0',
            'X-RateLimit-Reset-Tokens' => '13h20m',
            'Retry-After' => '3600',
        ]);

        $headers = OpenAiRateLimitDiagnostics::fromChain(new RuntimeException('wrapped', previous: new RateLimitException($response)));

        $this->assertNotNull($headers);
        $this->assertSame('500', $headers['x-ratelimit-limit-requests']);
        $this->assertSame('0', $headers['x-ratelimit-remaining-tokens']);
        $this->assertSame('13h20m', $headers['x-ratelimit-reset-tokens']);
        $this->assertSame('3600', $headers['retry-after']);
        $this->assertSame('(not present)', $headers['x-ratelimit-limit-tokens']);
    }

    #[Test]
    public function it_finds_the_rate_limit_exception_however_deep_it_is_nested(): void
    {
        $response = new Response(429, ['retry-after' => '86400']);
        $rateLimit = new RateLimitException($response);
        $middle = new RuntimeException('parseEntry failed', previous: $rateLimit);
        $outer = new RuntimeException("Evaluation arm 'luna-none' is incomplete", previous: $middle);

        $headers = OpenAiRateLimitDiagnostics::fromChain($outer);

        $this->assertNotNull($headers);
        $this->assertSame('86400', $headers['retry-after']);
    }
}
