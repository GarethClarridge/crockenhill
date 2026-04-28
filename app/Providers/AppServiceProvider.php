<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\BibleCanon;
use App\Support\ParallelTestingProcessLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /**
         * Performance Optimization: Register core stateless repositories, services, and presenters
         * as singletons to reduce object instantiation overhead during the request cycle.
         */
        $this->app->singleton(\App\Repositories\SermonRepository::class);
        $this->app->singleton(\App\Repositories\PageRepository::class);
        $this->app->singleton(\App\Services\PageImageCacheService::class);
        $this->app->singleton(\App\Presenters\PageImagePresenter::class);
        $this->app->singleton(\App\Presenters\PageCardPresenter::class);
        $this->app->singleton(\App\Presenters\RelatedPagePresenter::class);
        $this->app->singleton(\App\Services\PublicPageReadModelCache::class);
        $this->app->singleton(\App\Services\PublicMeetingReadModelCache::class);
        $this->app->singleton(\App\Presenters\SermonSitemapPresenter::class);
        $this->app->singleton(\App\Presenters\PageSitemapPresenter::class);
        $this->app->singleton(\App\Presenters\MeetingSitemapPresenter::class);
        $this->app->singleton(\App\Presenters\PreacherSitemapPresenter::class);
        $this->app->singleton(BibleCanon::class);

        /**
         * Performance Optimization: These collaborators carry request-level memoization,
         * so they should be shared within a single request / job lifecycle without
         * leaking state across long-running workers.
         */
        $this->app->scoped(\App\Services\SermonExposurePolicy::class);
        $this->app->scoped(\App\Services\SermonStorageService::class);
        $this->app->scoped(\App\Services\SermonTranscriptReader::class);
        $this->app->scoped(\App\Services\TranscriptStorageService::class);
        $this->app->scoped(\App\Presenters\SermonViewPresenter::class);
        $this->app->scoped(\App\Presenters\SermonItemListPresenter::class);
    }

    public function boot(): void
    {
        if (config('thumbnail-generation.enabled') && ! extension_loaded('gd')) {
            throw new \RuntimeException(
                'Thumbnail generation requires the GD PHP extension. '.
                'Install php-gd or disable thumbnail generation via THUMBNAIL_GENERATION_ENABLED=false.'
            );
        }

        if (isset($_SERVER['argv']) && is_array($_SERVER['argv'])) {
            $_SERVER['argv'] = ParallelTestingProcessLimiter::apply($_SERVER['argv']);
            $_SERVER['argc'] = count($_SERVER['argv']);
        }

        Password::defaults(function () {
            return Password::min(12)
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised();
        });
    }
}
