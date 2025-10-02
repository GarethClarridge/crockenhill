<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/church/members';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            // Check if this is a livestream status request and rate limiting is disabled
            if ($request->is('api/livestreams/processing/*/status') &&
                ! config('media-processing.rate_limiting.enabled', true)) {
                return Limit::none();
            }

            // Use configurable rate for livestream status endpoints when enabled
            if ($request->is('api/livestreams/processing/*/status')) {
                return Limit::perMinute(config('media-processing.rate_limiting.status.per_minute', 60))
                    ->by($request->user()?->id ?: $request->ip());
            }

            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Rate limiter for sermon file uploads - more restrictive due to processing overhead
        RateLimiter::for('sermon-upload', function (Request $request) {
            return [
                Limit::perMinute(5)->by($request->user()?->id ?: $request->ip()),
                Limit::perHour(20)->by($request->user()?->id ?: $request->ip()),
            ];
        });

        // Rate limiter for sermon processing retries - prevent retry spam
        RateLimiter::for('sermon-retry', function (Request $request) {
            return [
                Limit::perMinute(2)->by($request->user()?->id ?: $request->ip()),
                Limit::perHour(10)->by($request->user()?->id ?: $request->ip()),
            ];
        });

        // Rate limiter for sermon video uploads - more restrictive due to video processing overhead
        RateLimiter::for('sermon-video-upload', function (Request $request) {
            return [
                Limit::perMinute(1)->by($request->user()?->id ?: $request->ip()),
                Limit::perHour(5)->by($request->user()?->id ?: $request->ip()),
            ];
        });

        // Rate limiter for livestream video uploads - configurable and can be disabled in development
        RateLimiter::for('livestream-upload', function (Request $request) {
            if (! config('media-processing.rate_limiting.enabled', true)) {
                return Limit::none();
            }

            return [
                Limit::perMinute(config('media-processing.rate_limiting.upload.per_minute', 1))
                    ->by($request->user()?->id ?: $request->ip()),
                Limit::perHour(config('media-processing.rate_limiting.upload.per_hour', 5))
                    ->by($request->user()?->id ?: $request->ip()),
            ];
        });

        // Rate limiter for livestream processing retries - configurable and can be disabled in development
        RateLimiter::for('livestream-retry', function (Request $request) {
            if (! config('media-processing.rate_limiting.enabled', true)) {
                return Limit::none();
            }

            return [
                Limit::perMinute(config('media-processing.rate_limiting.retry.per_minute', 1))
                    ->by($request->user()?->id ?: $request->ip()),
                Limit::perHour(config('media-processing.rate_limiting.retry.per_hour', 3))
                    ->by($request->user()?->id ?: $request->ip()),
            ];
        });

        // Unified media upload rate limiter
        RateLimiter::for('media-upload', function (Request $request) {
            // More restrictive for video processing, less for audio
            if ($request->is('api/media/video') || $request->is('api/media/livestream')) {
                return [
                    Limit::perMinute(1)->by($request->user()?->id ?: $request->ip()),
                    Limit::perHour(5)->by($request->user()?->id ?: $request->ip()),
                ];
            }

            // Audio uploads
            return [
                Limit::perMinute(5)->by($request->user()?->id ?: $request->ip()),
                Limit::perHour(20)->by($request->user()?->id ?: $request->ip()),
            ];
        });

        // Unified media retry rate limiter
        RateLimiter::for('media-retry', function (Request $request) {
            return [
                Limit::perMinute(2)->by($request->user()?->id ?: $request->ip()),
                Limit::perHour(10)->by($request->user()?->id ?: $request->ip()),
            ];
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
