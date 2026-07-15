<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\SermonContentType;
use App\Jobs\MoveSermonToPrivateStorage;
use App\Models\Sermon;
use App\Services\Scripture\SermonScriptureFilterIndexService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class SermonObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly SermonScriptureFilterIndexService $scriptureFilterIndexService,
    ) {}

    public function saved(Sermon $sermon): void
    {
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
