<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Models\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RateLimitServiceProviderTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function media_upload_limiter_uses_stricter_limits_for_video_and_livestream_uploads(): void
    {
        $user = new User;
        $user->id = 123;

        // Test API path-based detection (Standard API usage)
        $videoRequest = Request::create('/api/media/video', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1']);
        $videoRequest->setUserResolver(fn (): User => $user);

        // Test web input-based detection (Web sermon upload usage)
        $webVideoRequest = Request::create('/admin/sermon-upload', 'POST', ['type' => 'video'], server: ['REMOTE_ADDR' => '127.0.0.1']);
        $webVideoRequest->setUserResolver(fn (): User => $user);

        $openLpRequest = Request::create('/api/services/openlp', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1']);
        $openLpRequest->setUserResolver(fn (): User => $user);

        $audioRequest = Request::create('/api/media/audio', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1']);
        $audioRequest->setUserResolver(fn (): User => $user);

        /** @var RateLimiter $rateLimiter */
        $rateLimiter = $this->app->make(RateLimiter::class);
        $limiter = $rateLimiter->limiter('media-upload');

        $this->assertNotNull($limiter);

        $videoLimits = $limiter($videoRequest);
        $webVideoLimits = $limiter($webVideoRequest);
        $openLpLimits = $limiter($openLpRequest);
        $audioLimits = $limiter($audioRequest);

        $this->assertSame([1, 5], array_map(fn ($limit): int => $limit->maxAttempts, $videoLimits));
        $this->assertSame([1, 5], array_map(fn ($limit): int => $limit->maxAttempts, $webVideoLimits));
        $this->assertSame([5, 20], array_map(fn ($limit): int => $limit->maxAttempts, $openLpLimits));
        $this->assertSame([5, 20], array_map(fn ($limit): int => $limit->maxAttempts, $audioLimits));
        $this->assertCount(2, array_unique(array_map(fn ($limit): string => (string) $limit->key, $videoLimits)));
        $this->assertTrue(
            collect($videoLimits)->every(fn ($limit): bool => str_starts_with((string) $limit->key, '123'))
        );
    }
}
