<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Preacher;
use App\Models\Sermon;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class PreacherObserver implements ShouldHandleEventsAfterCommit
{
    public function updated(Preacher $preacher): void
    {
        if (! $preacher->wasChanged('name')) {
            return;
        }

        Sermon::query()
            ->where('preacher_id', $preacher->id)
            ->update(['preacher' => $preacher->name]);
    }
}
