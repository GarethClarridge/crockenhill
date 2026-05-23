<?php

declare(strict_types=1);

namespace App\Providers;

use App\Presenters\MeetingSitemapPresenter;
use App\Presenters\PageCardPresenter;
use App\Presenters\PageImagePresenter;
use App\Presenters\PageSitemapPresenter;
use App\Presenters\PreacherSitemapPresenter;
use App\Presenters\RelatedPagePresenter;
use App\Presenters\SermonArchiveSeoPresenter;
use App\Presenters\SermonItemListPresenter;
use App\Presenters\SermonSitemapPresenter;
use App\Presenters\SermonViewPresenter;
use App\Repositories\PageRepository;
use App\Repositories\PreacherListRepository;
use App\Repositories\SermonRepository;
use App\Services\PageImageCacheService;
use App\Services\PublicMeetingReadModelCache;
use App\Services\PublicPageReadModelCache;
use App\Services\SermonExposurePolicy;
use App\Services\SermonStorageService;
use App\Services\SermonTranscriptReader;
use App\Services\TranscriptStorageService;
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
        $this->app->singleton(SermonRepository::class);
        $this->app->singleton(PreacherListRepository::class);
        $this->app->singleton(PageRepository::class);
        $this->app->singleton(PageImageCacheService::class);
        $this->app->singleton(PageImagePresenter::class);
        $this->app->singleton(PageCardPresenter::class);
        $this->app->singleton(RelatedPagePresenter::class);
        $this->app->singleton(PublicPageReadModelCache::class);
        $this->app->singleton(PublicMeetingReadModelCache::class);
        $this->app->singleton(SermonArchiveSeoPresenter::class);
        $this->app->singleton(PageSitemapPresenter::class);
        $this->app->singleton(MeetingSitemapPresenter::class);
        $this->app->singleton(BibleCanon::class);

        /**
         * Performance Optimization: These collaborators carry request-level memoization,
         * so they should be shared within a single request / job lifecycle without
         * leaking state across long-running workers.
         */
        $this->app->scoped(SermonExposurePolicy::class);
        $this->app->scoped(SermonStorageService::class);
        $this->app->scoped(SermonTranscriptReader::class);
        $this->app->scoped(TranscriptStorageService::class);
        $this->app->scoped(SermonViewPresenter::class);
        $this->app->scoped(SermonItemListPresenter::class);
        $this->app->scoped(SermonSitemapPresenter::class);
        $this->app->scoped(PreacherSitemapPresenter::class);
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
