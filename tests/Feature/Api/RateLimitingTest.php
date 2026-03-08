<?php

namespace Tests\Feature\Api;

use App\Enums\ApiTokenAbility;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->admin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        $this->token = $this->admin
            ->createToken('test', [ApiTokenAbility::MEDIA_PROCESS->value])
            ->plainTextToken;

        // Rebind ThrottleRequests so it picks up the app's RateLimiter singleton.
        // Without this, the middleware may resolve a stale instance that doesn't
        // reflect limiter overrides made during the test.
        $this->app->bind(
            ThrottleRequests::class,
            fn ($app) => new ThrottleRequests($app->make(\Illuminate\Cache\RateLimiter::class))
        );
    }

    /**
     * Override a named rate limiter to always return a 429 response.
     */
    private function forceThrottle(string $limiterName): void
    {
        RateLimiter::for($limiterName, function () {
            return new Response('Too Many Attempts.', 429);
        });
    }

    // -------------------------------------------------------------------------
    // media-upload limiter — audio
    // -------------------------------------------------------------------------

    #[Test]
    public function audio_upload_is_rate_limited(): void
    {
        $this->forceThrottle('media-upload');

        $file = UploadedFile::fake()->create('sermon.mp3', 10, 'audio/mpeg');

        $this->withToken($this->token)
            ->postJson('/api/media/audio', ['file' => $file])
            ->assertStatus(429);
    }

    // -------------------------------------------------------------------------
    // media-upload limiter — video
    // -------------------------------------------------------------------------

    #[Test]
    public function video_upload_is_rate_limited(): void
    {
        $this->forceThrottle('media-upload');

        $file = UploadedFile::fake()->create('sermon.mp4', 10, 'video/mp4');

        $this->withToken($this->token)
            ->postJson('/api/media/video', ['file' => $file])
            ->assertStatus(429);
    }

    // -------------------------------------------------------------------------
    // media-upload limiter — livestream
    // -------------------------------------------------------------------------

    #[Test]
    public function livestream_upload_is_rate_limited(): void
    {
        $this->forceThrottle('media-upload');

        $file = UploadedFile::fake()->create('livestream.mp4', 10, 'video/mp4');

        $this->withToken($this->token)
            ->postJson('/api/media/livestream', ['file' => $file])
            ->assertStatus(429);
    }

    // -------------------------------------------------------------------------
    // media-retry limiter
    // -------------------------------------------------------------------------

    #[Test]
    public function retry_is_rate_limited(): void
    {
        $this->forceThrottle('media-retry');

        $processingId = 'aaaaaaaa-0000-0000-0000-000000000010';

        $this->withToken($this->token)
            ->postJson("/api/media/processing/{$processingId}/retry")
            ->assertStatus(429);
    }

    #[Test]
    public function mailgun_inbound_webhook_is_rate_limited(): void
    {
        config()->set('service-tracking.mailgun.signing_key', 'test-signing-key');
        $this->forceThrottle('mailgun-inbound');

        $timestamp = (string) now()->getTimestamp();

        $this->postJson('/api/webhooks/mailgun/inbound', [
            'timestamp' => $timestamp,
            'token' => 'abc123',
            'signature' => hash_hmac('sha256', $timestamp.'abc123', 'test-signing-key'),
            'from' => 'Service Planning <planning@example.com>',
            'subject' => 'Order of Service for Sunday',
            'Message-Id' => '<message-1@example.com>',
            'body-plain' => "Welcome\nSong\nPrayer",
            'recipient' => 'oos@crockenhill.org',
        ])->assertStatus(429);
    }

    // -------------------------------------------------------------------------
    // Limiter keys are per-user not per-IP
    // -------------------------------------------------------------------------

    #[Test]
    public function rate_limit_buckets_are_independent_per_user(): void
    {
        // Override the media-upload limiter to block only the first admin
        $adminId = $this->admin->id;
        RateLimiter::for('media-upload', function (Request $request) use ($adminId) {
            if ($request->user()?->id === $adminId) {
                return new Response('Too Many Attempts.', 429);
            }

            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        $file = UploadedFile::fake()->create('sermon.mp3', 10, 'audio/mpeg');

        // First admin should be throttled
        $this->withToken($this->token)
            ->postJson('/api/media/audio', ['file' => $file])
            ->assertStatus(429);

        // Reset auth state between requests so Sanctum resolves the new user
        $this->app['auth']->forgetGuards();

        // A different admin should NOT be throttled
        $other = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
        $otherToken = $other->createToken('other', [ApiTokenAbility::MEDIA_PROCESS->value])->plainTextToken;

        $response = $this->withToken($otherToken)
            ->postJson('/api/media/audio', ['file' => $file]);

        $this->assertNotEquals(429, $response->getStatusCode());
    }
}
