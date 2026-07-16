<?php

declare(strict_types=1);

namespace App\Services\Public;

use App\Models\Meeting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class MeetingListCache
{
    public const ADMIN_LIST_CACHE_KEY = 'admin_meeting_list';

    private const CACHE_TTL = [300, 86400];

    /**
     * Meetings as a slug => heading map for admin dropdowns. Cached for 24 hours
     * to avoid the join + heading lookup on every render.
     *
     * @return Collection<string, string>
     */
    public function forAdminList(): Collection
    {
        return Cache::flexible(self::ADMIN_LIST_CACHE_KEY, self::CACHE_TTL, function (): Collection {
            return Meeting::query()
                ->select(['id', 'slug', 'page_id'])
                ->with('page:id,heading')
                ->get()
                ->mapWithKeys(fn (Meeting $m) => [$m->slug => $m->page->heading ?? $m->slug]);
        });
    }
}
