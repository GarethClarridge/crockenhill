<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\SermonContentType;
use App\Jobs\MoveSermonToPrivateStorage;
use App\Models\Sermon;
use App\Services\Public\PodcastFeedService;
use App\Services\Public\SermonRepository;
use App\Services\Scripture\SermonScriptureFilterIndexService;
use App\Services\Sermon\SermonExposurePolicy;
use App\Services\Sermon\SermonStorageService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class SermonObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly PodcastFeedService $podcastFeedService,
        private readonly SermonRepository $sermonRepository,
        private readonly SermonScriptureFilterIndexService $scriptureFilterIndexService,
        private readonly SermonStorageService $sermonStorageService,
    ) {}

    public function saved(Sermon $sermon): void
    {
        if ($sermon->wasRecentlyCreated || $sermon->wasChanged([
            'audio_file_path',
            'video_file_path',
            'transcript_file_path',
            'thumbnail_file_path',
        ])) {
            $this->sermonStorageService->clearCachedMetadata($sermon);
        }

        $isChildrensTalk = $sermon->content_type === SermonContentType::ChildrensTalk;
        $protectedMediaChanged = $sermon->wasRecentlyCreated || $sermon->wasChanged([
            'content_type',
            'audio_file_path',
            'video_file_path',
            'transcript_file_path',
            'thumbnail_file_path',
            'thumbnail_metadata',
        ]);

        if ($isChildrensTalk
            && $protectedMediaChanged
            && ! MoveSermonToPrivateStorage::isMovingSermon($sermon->id)
            && $this->hasNonPrivateProtectedAsset($sermon)
        ) {
            MoveSermonToPrivateStorage::dispatch($sermon->id);
        }

        if ($sermon->wasRecentlyCreated || $sermon->wasChanged(['reference', 'content_type'])) {
            $this->scriptureFilterIndexService->syncForSermon($sermon);
        }

        if ($sermon->wasChanged(SermonExposurePolicy::EXPOSURE_ATTRIBUTES)) {
            $this->forgetPublicListingCaches($sermon);
        }
    }

    public function deleted(Sermon $sermon): void
    {
        $this->forgetPublicListingCaches($sermon);
    }

    /**
     * Evict the cached public listings and podcast feeds a sermon can appear
     * in. A stale cached model would otherwise keep publishing its direct
     * media URLs (listing JSON-LD `VideoObject.contentUrl`, RSS enclosures)
     * after an operator hides or deletes them, until the stale window closes.
     */
    private function forgetPublicListingCaches(Sermon $sermon): void
    {
        $this->sermonRepository->forgetPublicListings($sermon);
        $this->podcastFeedService->forgetFeeds();
    }

    private function hasNonPrivateProtectedAsset(Sermon $sermon): bool
    {
        $paths = [
            $sermon->audio_file_path,
            $sermon->video_file_path,
            $sermon->transcript_file_path,
            $sermon->thumbnail_file_path,
            $sermon->plain_thumbnail_file_path,
            $sermon->card_thumbnail_file_path,
            $sermon->thumbnail_metadata?->overlayThumbnailPath,
        ];

        foreach ($sermon->thumbnail_candidates as $candidate) {
            foreach (['plain_path', 'card_path', 'overlay_path'] as $key) {
                $paths[] = $candidate[$key] ?? null;
            }
        }

        return collect($paths)->contains(
            fn (mixed $path): bool => is_string($path)
                && $path !== ''
                && ! str_starts_with($path, 'private/'),
        );
    }
}
