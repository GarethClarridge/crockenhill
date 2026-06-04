<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Data\ProcessingResult;
use App\Models\User;
use App\Services\Processing\UnifiedMediaProcessor;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RateLimitingSecurityTest extends TestCase
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
    public function audio_upload_is_rate_limited(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        // Mock the processor to avoid actual processing overhead
        $this->mock(UnifiedMediaProcessor::class, function ($mock) {
            $mock->shouldReceive('process')->andReturn(
                ProcessingResult::success('test-id', 'Started')
            );
        });

        Sanctum::actingAs($admin, ['*']);

        // The 'media-upload' limiter allows 5 per minute for audio.
        // We'll attempt 6 uploads.
        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/media/audio', [
                'file' => UploadedFile::fake()->create('sermon.mp3', 100),
            ]);

            $response->assertStatus(202);
        }

        // The 6th request should be throttled
        $response = $this->postJson('/api/media/audio', [
            'file' => UploadedFile::fake()->create('sermon.mp3', 100),
        ]);

        $response->assertStatus(429);
    }

    #[Test]
    public function video_upload_has_stricter_rate_limit(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        $this->mock(UnifiedMediaProcessor::class, function ($mock) {
            $mock->shouldReceive('process')->andReturn(
                ProcessingResult::success('test-id', 'Started')
            );
        });

        Sanctum::actingAs($admin, ['*']);

        // The 'media-upload' limiter allows 1 per minute for video/livestream.
        $response = $this->postJson('/api/media/video', [
            'file' => UploadedFile::fake()->create('sermon.mp4', 1000),
        ]);
        $response->assertStatus(202);

        // The 2nd request should be throttled
        $response = $this->postJson('/api/media/video', [
            'file' => UploadedFile::fake()->create('sermon.mp4', 1000),
        ]);
        $response->assertStatus(429);
    }
}
