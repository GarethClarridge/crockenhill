<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\PreacherAlias;
use App\Services\SermonIdentitySyncService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class PreacherAliasObserver implements ShouldHandleEventsAfterCommit
{
    /**
     * Handle the PreacherAlias "created" event.
     *
     * Automatically links unassigned sermons to the canonical preacher when
     * a matching alias is registered.
     */
    public function __construct(
        private readonly SermonIdentitySyncService $identitySyncService,
    ) {}

    /**
     * Handle the PreacherAlias "created" event.
     *
     * Automatically links unassigned sermons to the canonical preacher when
     * a matching alias is registered.
     */
    public function created(PreacherAlias $alias): void
    {
        $this->identitySyncService->backfillSermonsForAlias($alias);
    }
}
