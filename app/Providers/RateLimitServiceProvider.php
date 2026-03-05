<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class RateLimitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request): Limit {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('media-upload', function (Request $request): array {
            $key = $request->user()?->id ?: $request->ip();

            if ($request->is('api/media/video') || $request->is('api/media/livestream')) {
                return [
                    Limit::perMinute(1)->by($key),
                    Limit::perHour(5)->by($key),
                ];
            }

            return [
                Limit::perMinute(5)->by($key),
                Limit::perHour(20)->by($key),
            ];
        });

        RateLimiter::for('media-retry', function (Request $request): array {
            $key = $request->user()?->id ?: $request->ip();

            return [
                Limit::perMinute(2)->by($key),
                Limit::perHour(10)->by($key),
            ];
        });
    }
}
