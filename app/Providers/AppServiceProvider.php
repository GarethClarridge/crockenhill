<?php

declare(strict_types=1);

namespace App\Providers;

use App\Presenters\PageCardPresenter;
use App\Presenters\PageImagePresenter;
use App\Presenters\RelatedPagePresenter;
use App\Seo\SermonArchiveSeoPresenter;
use App\Seo\SermonItemListPresenter;
use App\Services\Media\Audio\SermonTranscriptReader;
use App\Services\Media\Audio\TranscriptStorageService;
use App\Services\Public\MeetingListCache;
use App\Services\Public\PageImageCacheService;
use App\Services\Public\PageListCache;
use App\Services\Public\PreacherListCache;
use App\Services\Public\PublicMeetingReadModelCache;
use App\Services\Public\PublicPageReadModelCache;
use App\Services\Public\SermonRepository;
use App\Services\Sermon\SermonExposurePolicy;
use App\Services\Sermon\SermonStorageService;
use App\Sitemap\MeetingSitemapPresenter;
use App\Sitemap\PageSitemapPresenter;
use App\Sitemap\PreacherSitemapPresenter;
use App\Sitemap\SermonSitemapPresenter;
use App\Support\BibleCanon;
use App\Support\ParallelTestingProcessLimiter;
use Faker\Factory as FakerFactory;
use Faker\Generator as FakerGenerator;
use Illuminate\Support\Carbon;
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
        $this->app->scoped(SermonRepository::class);
        $this->app->scoped(PageListCache::class);
        $this->app->scoped(PreacherListCache::class);
        $this->app->scoped(MeetingListCache::class);
        $this->app->scoped(SermonExposurePolicy::class);
        $this->app->scoped(SermonStorageService::class);
        $this->app->scoped(SermonTranscriptReader::class);
        $this->app->scoped(TranscriptStorageService::class);
        $this->app->scoped(SermonItemListPresenter::class);
        $this->app->scoped(SermonSitemapPresenter::class);
        $this->app->scoped(PreacherSitemapPresenter::class);

        $this->registerDeterministicFakerForVisualRegression();
    }

    public function boot(): void
    {
        $this->freezeClockForVisualRegression();

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

    /**
     * Bind a seeded Faker generator so factory output is reproducible across runs.
     *
     * Why: Playwright visual-regression baselines require byte-identical seed data
     * between the run that captured the baseline and every subsequent verification
     * run. Unseeded faker produces different sentences, dates, and paragraph
     * lengths each time `db:seed` runs, which changes page heights and breaks
     * snapshot diffs even when the application code has not changed.
     */
    private function registerDeterministicFakerForVisualRegression(): void
    {
        $seed = config('playwright.seed');

        if ($seed === null || $seed === '') {
            return;
        }

        $this->app->singleton(FakerGenerator::class, function () use ($seed): FakerGenerator {
            $generator = FakerFactory::create(config('app.faker_locale', 'en_US'));
            $generator->seed((int) $seed);

            return $generator;
        });
    }

    /**
     * Freeze Carbon::now() to a fixed instant so time-windowed queries are stable.
     *
     * Why: CalendarController::index filters events within `now()` to
     * `now()->addMonths(6)`. As real-time ticks forward across event boundaries,
     * the set of events on /calendar changes, which changes page height and
     * breaks visual regression snapshots.
     */
    private function freezeClockForVisualRegression(): void
    {
        $frozen = config('playwright.frozen_now');

        if ($frozen === null || $frozen === '') {
            return;
        }

        Carbon::setTestNow(Carbon::parse($frozen));
    }
}
