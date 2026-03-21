<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Meeting;
use App\Models\Page;
use App\Services\PageImageCacheService;
use App\Services\PublicMeetingReadModelCache;
use App\Services\PublicPageReadModelCache;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MediaLibraryCacheObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly PageImageCacheService $pageImageCacheService,
        private readonly PublicMeetingReadModelCache $publicMeetingReadModelCache,
        private readonly PublicPageReadModelCache $publicPageReadModelCache,
    ) {}

    public function created(Media $media): void
    {
        $this->clearCaches($media);
    }

    public function deleted(Media $media): void
    {
        $this->clearCaches($media);
    }

    public function updated(Media $media): void
    {
        $this->clearCaches($media);
    }

    private function clearCaches(Media $media): void
    {
        $model = $media->model;

        if ($model instanceof Page) {
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
    }
}
