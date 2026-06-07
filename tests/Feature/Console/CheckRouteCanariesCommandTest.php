<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Mail\RouteCanaryFailure;
use App\Models\Meeting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CheckRouteCanariesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'monitoring.enabled' => true,
            'monitoring.base_url' => 'http://canary.test',
            'monitoring.alert_email' => 'oncall@example.com',
            'monitoring.alert_cooldown_minutes' => 30,
        ]);

        Mail::fake();
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
    public function it_returns_success_when_all_canaries_pass(): void
    {
        $this->fakeHealthyCanaries();

        $this->artisan('monitoring:check-canaries')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    #[Test]
    public function it_fails_logs_and_emails_when_a_canary_returns_the_wrong_status(): void
    {
        Log::spy();
        $this->fakeHealthyCanaries(['*/sitemap.xml' => Http::response('boom', 500)]);

        $this->artisan('monitoring:check-canaries')->assertExitCode(1);

        Log::shouldHaveReceived('error')->once();
        Mail::assertSent(RouteCanaryFailure::class, fn (RouteCanaryFailure $mail): bool => $mail->hasTo('oncall@example.com')
            && array_key_exists('/sitemap.xml', $mail->failures));
    }

    #[Test]
    public function it_treats_a_missing_body_marker_as_a_failure(): void
    {
        // 200, but the body is not the real page — a soft error the status misses.
        $this->fakeHealthyCanaries(['*' => Http::response('<html>maintenance</html>', 200)]);

        $this->artisan('monitoring:check-canaries')->assertExitCode(1);

        Mail::assertSent(RouteCanaryFailure::class);
    }

    #[Test]
    public function it_throttles_alert_emails_per_url_within_the_cooldown(): void
    {
        $this->fakeHealthyCanaries(['*/sitemap.xml' => Http::response('boom', 500)]);

        $this->artisan('monitoring:check-canaries')->assertExitCode(1);
        $this->artisan('monitoring:check-canaries')->assertExitCode(1);

        // Both runs fail, but the alert is sent only once inside the cooldown.
        Mail::assertSent(RouteCanaryFailure::class, 1);
    }

    #[Test]
    public function it_logs_but_does_not_email_when_no_alert_address_is_configured(): void
    {
        config(['monitoring.alert_email' => null]);
        Log::spy();
        $this->fakeHealthyCanaries(['*/sitemap.xml' => Http::response('boom', 500)]);

        $this->artisan('monitoring:check-canaries')->assertExitCode(1);

        Log::shouldHaveReceived('error')->once();
        Mail::assertNothingSent();
    }

    #[Test]
    public function it_does_nothing_when_monitoring_is_disabled(): void
    {
        config(['monitoring.enabled' => false]);
        Http::fake();

        $this->artisan('monitoring:check-canaries')->assertExitCode(0);

        Http::assertNothingSent();
    }

    #[Test]
    public function it_hits_cached_routes_twice(): void
    {
        Meeting::factory()->create(['slug' => 'sunday-mornings', 'page_id' => null]);
        $this->fakeHealthyCanaries();

        $this->artisan('monitoring:check-canaries')->assertExitCode(0);

        $meetingRequests = Http::recorded(
            fn ($request): bool => str_contains($request->url(), '/community/sunday-mornings')
        );

        $this->assertCount(2, $meetingRequests);
    }
}
