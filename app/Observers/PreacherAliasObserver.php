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

        $query = Sermon::query()->whereNull('preacher_id');

        // Use REGEXP_REPLACE on MySQL to normalize internal whitespace during matching.
        // This ensures "J.   Edwards" matches "j. edwards".
        if (config('database.default') === 'mysql') {
            $query->whereRaw("LOWER(REGEXP_REPLACE(TRIM(preacher), '[[:space:]]+', ' ')) = ?", [$alias->alias]);
        } else {
            $query->whereRaw('LOWER(TRIM(preacher)) = ?', [$alias->alias]);
        }

        $query->update([
            'preacher_id' => $preacher->id,
            'preacher' => $preacher->name,
        ]);
    }
}
