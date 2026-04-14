<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\SermonContentType;
use App\Jobs\MoveSermonToPrivateStorage;
use App\Models\Sermon;
use App\Services\SermonScriptureFilterIndexService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class SermonObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly SermonScriptureFilterIndexService $scriptureFilterIndexService,
    ) {}

    public function saved(Sermon $sermon): void
    {
        $isChildrensTalk = $sermon->content_type === SermonContentType::ChildrensTalk;
        $typeJustChanged = $sermon->wasRecentlyCreated || $sermon->wasChanged('content_type');

        if ($isChildrensTalk && $typeJustChanged) {
            MoveSermonToPrivateStorage::dispatch($sermon->id);
        }

        if ($sermon->wasRecentlyCreated || $sermon->wasChanged(['reference', 'content_type'])) {
            $this->scriptureFilterIndexService->syncForSermon($sermon);
        }
    }
}
