<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\OpenAiFlexFallback;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\RateLimitException;
use OpenAI\Responses\Chat\CreateResponse;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class OpenAiFlexFallbackTest extends TestCase
{
    #[Test]
    public function it_sends_once_and_reports_the_requested_tier_when_the_provider_accepts_it(): void
    {
        $sent = [];

        $result = OpenAiFlexFallback::send(
            ['model' => 'gpt-5.6-luna', 'service_tier' => 'flex'],
            function (array $payload) use (&$sent): CreateResponse {
                $sent[] = $payload['service_tier'];

                return CreateResponse::fake();
            },
            'service_structure',
        );

        $this->assertSame(['flex'], $sent);
        $this->assertSame('flex', $result->serviceTier);
        $this->assertFalse($result->fellBackFromFlex);
    }

    #[Test]
    public function it_re_sends_on_the_default_tier_when_flex_has_no_capacity(): void
    {
        $sent = [];

        $result = OpenAiFlexFallback::send(
            ['model' => 'gpt-5.6-luna', 'service_tier' => 'flex'],
            function (array $payload) use (&$sent): CreateResponse {
                $sent[] = $payload['service_tier'];

                if (count($sent) === 1) {
                    throw $this->flexUnavailable();
                }

                return CreateResponse::fake();
            },
            'service_structure',
        );

        $this->assertSame(['flex', 'default'], $sent);
        $this->assertSame('default', $result->serviceTier);
        $this->assertTrue($result->fellBackFromFlex);
    }

    /**
     * The distinction the whole class exists for. A genuine rate limit is about this account, and
     * re-sending it on another tier would neither succeed nor be honest about why it failed — the
     * job's own backoff owns that case.
     */
    #[Test]
    public function it_does_not_change_tier_for_a_429_that_is_a_genuine_rate_limit(): void
    {
        $sent = 0;

        $this->expectException(RateLimitException::class);

        try {
            OpenAiFlexFallback::send(
                ['model' => 'gpt-5.6-luna', 'service_tier' => 'flex'],
                function () use (&$sent): CreateResponse {
                    $sent++;

                    throw new RateLimitException(new Response(429, [], json_encode([
                        'error' => ['code' => 'rate_limit_exceeded', 'type' => 'requests'],
                    ], JSON_THROW_ON_ERROR)));
                },
                'service_structure',
            );
        } finally {
            $this->assertSame(1, $sent);
        }
    }

    #[Test]
    public function it_does_not_change_tier_when_the_caller_never_asked_for_flex(): void
    {
        $sent = 0;

        $this->expectException(RateLimitException::class);

        try {
            OpenAiFlexFallback::send(
                ['model' => 'gpt-5.6-luna', 'service_tier' => 'default'],
                function () use (&$sent): CreateResponse {
                    $sent++;

                    throw $this->flexUnavailable();
                },
                'service_structure',
            );
        } finally {
            $this->assertSame(1, $sent);
        }
    }

    /**
     * The 429 rarely arrives as itself: `SermonAnalysisService` reports "OpenAI API call failed."
     * and attaches the cause. Reading only the outermost exception is what made the 2026-09-02
     * capacity refusals indistinguishable from rate limiting.
     */
    #[Test]
    public function it_recognises_flex_unavailability_through_a_wrapped_cause(): void
    {
        $wrapped = new RuntimeException('OpenAI API call failed.', previous: $this->flexUnavailable());

        $this->assertTrue(OpenAiFlexFallback::isFlexUnavailable($wrapped));
        $this->assertSame('flex_unavailable', OpenAiFlexFallback::errorCode($wrapped));
    }

    #[Test]
    public function it_reads_the_error_code_from_an_error_exception_429_too(): void
    {
        $exception = new ErrorException(
            ['message' => 'Flex does not have sufficient resources available.', 'type' => 'resource_unavailable', 'code' => 'flex_unavailable'],
            new Response(429),
        );

        $this->assertTrue(OpenAiFlexFallback::isFlexUnavailable($exception));
    }

    #[Test]
    public function it_reports_no_error_code_for_a_failure_that_is_not_a_429(): void
    {
        $this->assertNull(OpenAiFlexFallback::errorCode(new RuntimeException('connection reset')));
        $this->assertFalse(OpenAiFlexFallback::isFlexUnavailable(new RuntimeException('connection reset')));
    }

    /**
     * Logging the refusal is the remediation, not a nicety. Without the code and the headers the
     * only surviving evidence is the client's hardcoded "Request rate limit has been exceeded.",
     * which describes a completely different failure.
     */
    #[Test]
    public function it_logs_the_provider_error_code_and_rate_limit_headroom_for_every_429(): void
    {
        Log::spy();

        OpenAiFlexFallback::send(
            ['model' => 'gpt-5.6-luna', 'service_tier' => 'flex'],
            (function () {
                $calls = 0;

                return function () use (&$calls): CreateResponse {
                    $calls++;

                    if ($calls === 1) {
                        throw $this->flexUnavailable();
                    }

                    return CreateResponse::fake();
                };
            })(),
            'service_structure',
        );

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'OpenAI refused a request with 429'
                    && $context['operation'] === 'service_structure'
                    && $context['model'] === 'gpt-5.6-luna'
                    && $context['requested_service_tier'] === 'flex'
                    && $context['error_code'] === 'flex_unavailable'
                    && $context['flex_unavailable'] === true
                    && $context['x-ratelimit-remaining-tokens'] === '1999997'
                    && $context['retry-after'] === '300';
            });
    }

    /**
     * The response body OpenAI actually returned on 2026-09-02, headers included: nearly the whole
     * budget unspent, which is what proves the refusal was about the tier and not the account.
     */
    private function flexUnavailable(): RateLimitException
    {
        return new RateLimitException(new Response(429, [
            'x-ratelimit-limit-requests' => '5000',
            'x-ratelimit-remaining-requests' => '4999',
            'x-ratelimit-limit-tokens' => '2000000',
            'x-ratelimit-remaining-tokens' => '1999997',
            'retry-after' => '300',
        ], json_encode([
            'error' => [
                'message' => 'Flex does not have sufficient resources available to fulfill your request.',
                'type' => 'resource_unavailable',
                'param' => null,
                'code' => 'flex_unavailable',
            ],
        ], JSON_THROW_ON_ERROR)));
    }
}
