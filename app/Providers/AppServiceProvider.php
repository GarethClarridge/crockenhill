<?php

namespace App\Providers;

use App\Contracts\TranscriptionServiceInterface;
use App\HealthChecks\OpenAIHealthCheck;
use App\HealthChecks\SermonProcessingQueueHealthCheck;
use App\HealthChecks\StorageHealthCheck;
use App\Models\Meeting;
use App\Models\Page;
use App\Models\Sermon;
use App\Observers\SitemapCacheObserver;
use App\Services\AudioTranscriptionService;
use App\Services\MockTranscriptionService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // Force URL generation to use APP_URL (important for sitemap, emails, etc.)
        URL::forceRootUrl(config('app.url'));
        if (str_starts_with(config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // Register custom health checks for Laravel 12
        Event::listen(DiagnosingHealth::class, function () {
            // Run OpenAI health check
            $openAICheck = new OpenAIHealthCheck;
            $openAIResult = $openAICheck->run();
            if ($openAIResult['status'] === 'error') {
                throw new \Exception('OpenAI API health check failed: '.$openAIResult['message']);
            }

            // Run sermon processing queue health check
            $queueCheck = new SermonProcessingQueueHealthCheck;
            $queueResult = $queueCheck->run();
            if ($queueResult['status'] === 'error') {
                throw new \Exception('Sermon processing queue health check failed: '.$queueResult['message']);
            }

            // Run storage health check
            $storageCheck = new StorageHealthCheck;
            $storageResult = $storageCheck->run();
            if ($storageResult['status'] === 'error') {
                throw new \Exception('Storage health check failed: '.$storageResult['message']);
            }
        });

        // Share user with all views (per-request, not at boot time)
        View::composer('*', function ($view) {
            $view->with('user', Auth::user());
        });

        // Share $pages with the header component
        View::composer('components.layout.header', function ($view) {
            $view->with('pages', Page::isNavigation()->get());
        });

        // Register sitemap cache observers
        Sermon::observe(SitemapCacheObserver::class);
        Page::observe(SitemapCacheObserver::class);
        Meeting::observe(SitemapCacheObserver::class);

        // Rate limiters (moved from RouteServiceProvider)
        $this->configureRateLimiting();
    }

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            if ($request->is('api/livestreams/processing/*/status') &&
                ! config('media-processing.rate_limiting.enabled', true)) {
                return Limit::none();
            }

            if ($request->is('api/livestreams/processing/*/status')) {
                return Limit::perMinute(config('media-processing.rate_limiting.status.per_minute', 60))
                    ->by($request->user()?->id ?: $request->ip());
            }

            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('sermon-upload', function (Request $request) {
            return [
                Limit::perMinute(5)->by($request->user()?->id ?: $request->ip()),
                Limit::perHour(20)->by($request->user()?->id ?: $request->ip()),
            ];
        });

        RateLimiter::for('sermon-retry', function (Request $request) {
            return [
                Limit::perMinute(2)->by($request->user()?->id ?: $request->ip()),
                Limit::perHour(10)->by($request->user()?->id ?: $request->ip()),
            ];
        });

        RateLimiter::for('sermon-video-upload', function (Request $request) {
            return [
                Limit::perMinute(1)->by($request->user()?->id ?: $request->ip()),
                Limit::perHour(5)->by($request->user()?->id ?: $request->ip()),
            ];
        });

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

        RateLimiter::for('media-upload', function (Request $request) {
            if ($request->is('api/media/video') || $request->is('api/media/livestream')) {
                return [
                    Limit::perMinute(1)->by($request->user()?->id ?: $request->ip()),
                    Limit::perHour(5)->by($request->user()?->id ?: $request->ip()),
                ];
            }

            return [
                Limit::perMinute(5)->by($request->user()?->id ?: $request->ip()),
                Limit::perHour(20)->by($request->user()?->id ?: $request->ip()),
            ];
        });

        RateLimiter::for('media-retry', function (Request $request) {
            return [
                Limit::perMinute(2)->by($request->user()?->id ?: $request->ip()),
                Limit::perHour(10)->by($request->user()?->id ?: $request->ip()),
            ];
        });
    }

    /**
     * Register any application services.
     *
     * This service provider is a great spot to register your various container
     * bindings with the application. As you can see, we are registering our
     * "Registrar" implementation here. You can add your own bindings too!
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind(
            'Illuminate\Contracts\Auth\Registrar',
            'App\Services\Registrar'
        );

        $this->app->bind('path.public', function () {
            return base_path().'/public';
        });

        // Bind transcription service based on environment configuration
        $this->app->bind(TranscriptionServiceInterface::class, function ($app) {
            // Check both 'service' and 'service_type' for backward compatibility
            $serviceType = config('media-processing.transcription.service_type')
                ?? config('media-processing.transcription.service', 'openai');

            switch ($serviceType) {
                case 'mock':
                    return $app->make(MockTranscriptionService::class);
                case 'openai':
                default:
                    return $app->make(AudioTranscriptionService::class);
            }
        });

        // if ($this->app->environment('local', 'testing')) {
        //   $this->app->register(DuskServiceProvider::class);
        // }
    }
}
