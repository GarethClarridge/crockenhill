<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Meeting;
use App\Models\Page;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Repositories\PageRepository;
use App\Repositories\PreacherListRepository;
use App\Repositories\SermonRepository;
use App\Services\PageImageCacheService;
use App\Services\PodcastFeedService;
use App\Services\PublicMeetingReadModelCache;
use App\Services\PublicPageReadModelCache;
use App\Services\SermonStorageService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Cache;

class SitemapCacheObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly SermonRepository $sermonRepository,
        private readonly PreacherListRepository $preacherListRepository,
        private readonly PageRepository $pageRepository,
        private readonly PageImageCacheService $pageImageCacheService,
        private readonly PodcastFeedService $podcastFeedService,
        private readonly PublicMeetingReadModelCache $publicMeetingReadModelCache,
        private readonly PublicPageReadModelCache $publicPageReadModelCache,
        private readonly SermonStorageService $sermonStorageService,
    ) {}

    /**
     * Handle the model "created" event.
     */
    public function created(mixed $model): void
    {
        $this->clearCaches($model);
    }

    /**
     * Handle the model "updated" event.
     */
    public function updated(mixed $model): void
    {
        $this->clearCaches($model);
    }

    /**
     * Handle the model "deleted" event.
     */
    public function deleted(mixed $model): void
    {
        $this->clearCaches($model);
    }

    /**
     * Clear all related application caches when models change.
     */
    private function clearCaches(mixed $model): void
    {
        Cache::forget('sitemap');
        Cache::forget('nav_pages');
        Cache::forget('admin_meeting_list');

        $this->preacherListRepository->forgetFlexibleCaches();
        $this->preacherListRepository->clearInternalCaches();
        $this->sermonRepository->clearInternalCaches();
        $this->pageRepository->clearInternalCaches();

        if ($model instanceof Page) {
            $this->pageRepository->clearAreaCache($model->area);
            $this->pageImageCacheService->forget($model);
            $this->publicPageReadModelCache->forget($model);
            $model->loadMissing('meeting');

            if ($model->meeting !== null) {
                $this->publicMeetingReadModelCache->forget($model->meeting);
            }
        }

        if ($model instanceof Meeting) {
            $this->publicMeetingReadModelCache->forget($model);
        }

        if ($model instanceof Sermon) {
            $this->sermonStorageService->clearCachedMetadata($model);
        }

        $targetModel = ($model instanceof Sermon || $model instanceof Preacher)
            ? $model
            : null;

        $this->sermonRepository->clearListingCaches($targetModel);
        $this->clearPodcastFeedCache($model);
    }

    private function clearPodcastFeedCache(mixed $model): void
    {
        if (! $model instanceof Sermon && ! $model instanceof Preacher) {
            return;
        }

        $this->podcastFeedService->clearCache();
    }
}
