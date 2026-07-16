<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Meeting;
use App\Models\Page;
use App\Services\Public\PageImageCacheService;
use App\Services\Public\PublicMeetingReadModelCache;
use App\Services\Public\PublicPageReadModelCache;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class PublicReadModelCacheObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly PageImageCacheService $pageImageCacheService,
        private readonly PublicMeetingReadModelCache $publicMeetingReadModelCache,
        private readonly PublicPageReadModelCache $publicPageReadModelCache,
    ) {}

    public function created(Page|Meeting $model): void
    {
        $this->forget($model);
    }

    public function updated(Page|Meeting $model): void
    {
        $this->forget($model);
    }

    public function deleted(Page|Meeting $model): void
    {
        $this->forget($model);
    }

    private function forget(Page|Meeting $model): void
    {
        if ($model instanceof Meeting) {
            $this->publicMeetingReadModelCache->forget($model);

            return;
        }

        $this->pageImageCacheService->forget($model);
        $this->publicPageReadModelCache->forget($model);
        $model->loadMissing('meeting');

        if ($model->meeting !== null) {
            $this->publicMeetingReadModelCache->forget($model->meeting);
        }
    }
}
