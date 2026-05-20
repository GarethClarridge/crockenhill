<?php

declare(strict_types=1);

namespace Tests\Feature\RateLimiting;

use App\Models\Sermon;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublicNotFoundLimiterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(
            ThrottleRequests::class,
            fn ($app) => new ThrottleRequests($app->make(RateLimiter::class))
        );
    }

    #[Test]
    public function unknown_sermon_slugs_are_blocked_after_fifteen_requests_per_minute(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $response = $this->get('/christ/sermons/this-sermon-does-not-exist-'.$i);

            $response->assertStatus(404);
        }

        $this->get('/christ/sermons/still-missing')->assertStatus(429);
    }

    #[Test]
    public function successful_responses_do_not_consume_the_limit(): void
    {
        $sermon = Sermon::factory()->create();

        for ($i = 0; $i < 30; $i++) {
            $response = $this->followingRedirects()->get(route('sermons.show', $sermon->slug));

            $response->assertStatus(200);
        }

        // Even after many valid requests, the limit for 404s is untouched.
        $this->get('/christ/sermons/non-existent')->assertStatus(404);
    }

    #[Test]
    public function successful_responses_interleaved_with_404s_only_count_the_404s(): void
    {
        $sermon = Sermon::factory()->create();

        // 14 misses — still under the limit.
        for ($i = 0; $i < 14; $i++) {
            $this->get('/christ/sermons/missing-'.$i)->assertStatus(404);
        }

        // A 200 response in the middle does not count.
        $this->followingRedirects()
            ->get(route('sermons.show', $sermon->slug))
            ->assertStatus(200);

        // The 15th 404 still passes (we've only had 14 so far).
        $this->get('/christ/sermons/missing-14')->assertStatus(404);

        // The 16th 404 is blocked.
        $this->get('/christ/sermons/missing-15')->assertStatus(429);
    }

    #[Test]
    public function unknown_preacher_slugs_are_rate_limited(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->get('/christ/sermons/preachers/ghost-preacher-'.$i)->assertStatus(404);
        }

        $this->get('/christ/sermons/preachers/another-ghost')->assertStatus(429);
    }
}
