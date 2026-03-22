<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Preacher;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\DB;

class PreacherObserver implements ShouldHandleEventsAfterCommit
{
    public function updated(Preacher $preacher): void
    {
        if (! $preacher->wasChanged('name')) {
            return;
        }

        DB::table('sermons')
            ->where('preacher_id', $preacher->id)
            ->update(['preacher' => $preacher->name]);
    }
}
