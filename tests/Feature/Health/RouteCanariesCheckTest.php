<?php

declare(strict_types=1);

namespace Tests\Feature\Health;

use App\Models\Meeting;
use App\Services\Monitoring\Checks\RouteCanariesCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Health\Enums\Status;
use Tests\TestCase;

/**
 * Probe behaviour ported from the retired monitoring:check-canaries command
 * test; alert throttling and recipients now live in the laravel-health
 * notification pipeline, so only probing and result mapping are covered here.
 */
class RouteCanariesCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'health.route_canaries.enabled' => true,
            'health.route_canaries.base_url' => 'http://canary.test',
        ]);
    }

    /**
     * Fakes the outbound canary requests. Static canaries return their expected
     * good responses unless overridden by the supplied patterns.
     *
     * @param  array<string, Response>  $overrides
     */
    private function fakeHealthyCanaries(array $overrides = []): void
    {
        // Specific patterns first; overrides replace them in place. The '*'
        // catch-all must stay last because Http::fake uses the first match.
        $stubs = array_merge([
            '*/sitemap.xml' => Http::response('<urlset></urlset>', 200),
            '*/church/members' => Http::response('', 302),
        ], $overrides);

        if (! array_key_exists('*', $stubs)) {
            $stubs['*'] = Http::response('<html><body>Crockenhill Baptist Church</body></html>', 200);
        }

        Http::fake($stubs);
    }

    #[Test]
    public function it_reports_ok_when_all_canaries_pass(): void
    {
        $this->fakeHealthyCanaries();

        $result = RouteCanariesCheck::new()->run();

        $this->assertSame(Status::ok(), $result->status);
        $this->assertStringContainsString('passed', $result->shortSummary);
    }

    #[Test]
    public function it_fails_and_logs_when_a_canary_returns_the_wrong_status(): void
    {
        Log::spy();
        $this->fakeHealthyCanaries(['*/sitemap.xml' => Http::response('boom', 500)]);

        $result = RouteCanariesCheck::new()->run();

        $this->assertSame(Status::failed(), $result->status);
        $this->assertStringContainsString('/sitemap.xml — expected 200, got 500', $result->notificationMessage);
        $this->assertArrayHasKey('/sitemap.xml', $result->meta);
        Log::shouldHaveReceived('error')->once();
    }

    #[Test]
    public function it_treats_a_missing_body_marker_as_a_failure(): void
    {
        // 200, but the body is not the real page — a soft error the status misses.
        $this->fakeHealthyCanaries(['*' => Http::response('<html>maintenance</html>', 200)]);

        $result = RouteCanariesCheck::new()->run();

        $this->assertSame(Status::failed(), $result->status);
        $this->assertStringContainsString('body marker', $result->notificationMessage);
    }

    #[Test]
    public function it_reports_ok_without_probing_when_monitoring_is_disabled(): void
    {
        config(['health.route_canaries.enabled' => false]);
        Http::fake();

        $result = RouteCanariesCheck::new()->run();

        $this->assertSame(Status::ok(), $result->status);
        $this->assertSame('Disabled', $result->shortSummary);
        Http::assertNothingSent();
    }

    #[Test]
    public function it_hits_cached_routes_twice(): void
    {
        Meeting::factory()->create(['slug' => 'sunday-mornings', 'page_id' => null]);
        $this->fakeHealthyCanaries();

        RouteCanariesCheck::new()->run();

        $meetingRequests = Http::recorded(
            fn ($request): bool => str_contains($request->url(), '/community/sunday-mornings')
        );

        $this->assertCount(2, $meetingRequests);
    }
}
