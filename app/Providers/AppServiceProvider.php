<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\HistoricReleaseObjectStore;
use App\Contracts\HistoricSourceFilesystemInspector;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\ChurchServiceMergeProposal;
use App\Models\ChurchServiceReviewSession;
use App\Models\ChurchServiceSourceRecord;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\SermonProcessingStep;
use App\Models\ServiceSection;
use App\Models\SongVideo;
use App\Presenters\PageCardPresenter;
use App\Presenters\PageImagePresenter;
use App\Presenters\RelatedPagePresenter;
use App\Seo\SermonArchiveSeoPresenter;
use App\Seo\SermonItemListPresenter;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Services\Import\DarwinHistoricSourceFilesystemInspector;
use App\Services\Import\FilesystemHistoricReleaseObjectStore;
use App\Services\Import\HistoricImportMutationFreeze;
use App\Services\Import\LinuxHistoricSourceFilesystemInspector;
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
use App\Sitemap\SermonSitemapPresenter;
use App\Support\BibleCanon;
use App\Support\ParallelTestingProcessLimiter;
use Faker\Factory as FakerFactory;
use Faker\Generator as FakerGenerator;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

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
        $this->app->singleton(BibleCanon::class);

        /**
         * HIR7: every write to a public release destination goes through this
         * boundary, so a test can substitute a deterministic one and production
         * cannot accidentally get an implementation that emulates a capability
         * the real store lacks.
         */
        $this->app->bind(HistoricReleaseObjectStore::class, FilesystemHistoricReleaseObjectStore::class);

        /**
         * HIR-D4 approved Darwin and production Linux and nothing else. An
         * unknown platform fails closed here rather than reaching a verifier
         * that would have to guess at mount and protection facts.
         */
        $this->app->bind(HistoricSourceFilesystemInspector::class, static fn (): HistoricSourceFilesystemInspector => match (PHP_OS_FAMILY) {
            'Darwin' => new DarwinHistoricSourceFilesystemInspector,
            'Linux' => new LinuxHistoricSourceFilesystemInspector,
            default => throw new RuntimeException(
                'Historic source acquisition is only supported on Darwin and Linux hosts; '
                .PHP_OS_FAMILY.' cannot expose the required mount and protection facts.'
            ),
        });

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
        $this->app->scoped(HistoricStagingContextRegistry::class);
        $this->app->scoped(HistoricImportMutationFreeze::class);

        $this->registerDeterministicFakerForVisualRegression();
    }

    public function boot(): void
    {
        $this->freezeClockForVisualRegression();
        $this->registerHistoricStagingQueueContext();
        $this->registerHistoricImportMutationFreeze();

        if (config('thumbnail-generation.enabled') && ! extension_loaded('gd')) {
            throw new RuntimeException(
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

    private function registerHistoricImportMutationFreeze(): void
    {
        $guard = static function (): void {
            app(HistoricImportMutationFreeze::class)->assertMutationAllowed();
        };

        foreach ([
            ChurchService::class,
            ChurchServiceItem::class,
            ChurchServiceMergeProposal::class,
            ChurchServiceReviewSession::class,
            ChurchServiceSourceRecord::class,
            LivestreamSegment::class,
            MediaProcessingLog::class,
            Sermon::class,
            SermonProcessingStep::class,
            ServiceSection::class,
            SongVideo::class,
        ] as $model) {
            $model::saving($guard);
            $model::deleting($guard);
        }
    }

    private function registerHistoricStagingQueueContext(): void
    {
        Queue::createPayloadUsing(fn (): array => app(HistoricStagingContextRegistry::class)->queuePayload());

        Queue::before(function (JobProcessing $event): void {
            app(HistoricStagingContextRegistry::class)->activateQueuePayload($event->job->payload());
        });

        $deactivate = static function (): void {
            app(HistoricStagingContextRegistry::class)->deactivate();
        };

        Queue::after($deactivate);
        Queue::exceptionOccurred($deactivate);
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
