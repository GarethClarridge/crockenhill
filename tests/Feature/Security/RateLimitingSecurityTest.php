<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RateLimitingSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Rebind ThrottleRequests so it picks up the app's RateLimiter singleton.
        // Without this, the middleware may resolve a stale instance that doesn't
        // reflect limiter state across multiple requests in the same test.
        $this->app->bind(
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
            fn ($app) => new \Illuminate\Routing\Middleware\ThrottleRequests($app->make(\Illuminate\Cache\RateLimiter::class))
        );
    }

    #[Test]
    public function web_sermon_upload_is_now_rate_limited(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        // Mock the processor to avoid actual processing overhead
        $this->mock(\App\Services\UnifiedMediaProcessor::class, function ($mock) {
            $mock->shouldReceive('process')->andReturn(
                \App\Services\ProcessingResult::success('test-id', 'Started')
            );
        });

        $this->actingAs($admin);

        // The 'media-upload' limiter allows 5 per minute for audio.
        // We'll attempt 6 uploads.
        for ($i = 0; $i < 5; $i++) {
            $response = $this->post('/admin/sermon-upload', [
                'type' => 'audio',
                'file' => UploadedFile::fake()->create('sermon.mp3', 100),
            ]);

            $response->assertStatus(302);
        }

        // The 6th request should be throttled
        $response = $this->post('/admin/sermon-upload', [
            'type' => 'audio',
            'file' => UploadedFile::fake()->create('sermon.mp3', 100),
        ]);

        $response->assertStatus(429);
    }

    #[Test]
    public function web_video_upload_has_stricter_rate_limit(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        $this->mock(\App\Services\UnifiedMediaProcessor::class, function ($mock) {
            $mock->shouldReceive('process')->andReturn(
                \App\Services\ProcessingResult::success('test-id', 'Started')
            );
        });

        $this->actingAs($admin);

        // The 'media-upload' limiter allows 1 per minute for video/livestream.
        $response = $this->post('/admin/sermon-upload', [
            'type' => 'video',
            'file' => UploadedFile::fake()->create('sermon.mp4', 1000),
        ]);
        $response->assertStatus(302);

        // The 2nd request should be throttled
        $response = $this->post('/admin/sermon-upload', [
            'type' => 'video',
            'file' => UploadedFile::fake()->create('sermon.mp4', 1000),
        ]);
        $response->assertStatus(429);
    }
}
