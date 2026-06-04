<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Meeting;
use App\Models\Page;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Services\Public\PageImageCacheService;
use App\Services\Public\PageListCache;
use App\Services\Public\PodcastFeedService;
use App\Services\Public\PublicMeetingReadModelCache;
use App\Services\Public\PublicPageReadModelCache;
use App\Services\Public\SermonRepository;
use App\Services\Sermon\SermonStorageService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Cache;

class SitemapCacheObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly SermonRepository $sermonRepository,
        private readonly PageListCache $pageRepository,
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
        Cache::forget('admin_preacher_list');
        Cache::forget('public_preacher_list');
        Cache::forget('admin_meeting_list');

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
