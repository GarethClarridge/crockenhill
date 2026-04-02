<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Preacher;
use App\Models\PreacherAlias;
use App\Models\Sermon;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class PreacherAliasObserver implements ShouldHandleEventsAfterCommit
{
    /**
     * Handle the PreacherAlias "created" event.
     *
     * Automatically links unassigned sermons to the canonical preacher when
     * a matching alias is registered.
     */
    public function created(PreacherAlias $alias): void
    {
        $preacher = $alias->preacher;

        if (! $preacher instanceof Preacher) {
            return;
        }

        Sermon::query()
            ->whereNull('preacher_id')
            ->whereRaw('LOWER(TRIM(preacher)) = ?', [$alias->alias])
            ->update([
                'preacher_id' => $preacher->id,
                'preacher' => $preacher->name,
            ]);
    }
}
