<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Meeting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class MeetingListRepository
{
    public const ADMIN_LIST_CACHE_KEY = 'admin_meeting_list';

    private const CACHE_TTL = [86400, 172800];

    /** @var Collection<string, string>|null */
    private ?Collection $memoizedAdminList = null;

    /**
     * Meetings as a slug => heading map for admin dropdowns. Cached for 24 hours
     * to avoid the join + heading lookup on every render.
     *
     * Performance Optimization: Memoizes the result for the duration of the
     * request to avoid redundant flexible cache lookups when building
     * multiple admin dropdowns.
     *
     * @return Collection<string, string>
     */
    public function forAdminList(): Collection
    {
        if ($this->memoizedAdminList !== null) {
            return $this->memoizedAdminList;
        }

        return $this->memoizedAdminList = Cache::flexible(self::ADMIN_LIST_CACHE_KEY, self::CACHE_TTL, function (): Collection {
            return Meeting::query()
                ->select(['id', 'slug', 'page_id'])
                ->with('page:id,heading')
                ->get()
                ->mapWithKeys(fn (Meeting $m) => [$m->slug => $m->page->heading ?? $m->slug]);
        });
    }

    /**
     * Clear all internal memoization caches.
     * Useful for long-running processes or tests.
     */
    public function clearInternalCaches(): void
    {
        $this->memoizedAdminList = null;
    }
}
