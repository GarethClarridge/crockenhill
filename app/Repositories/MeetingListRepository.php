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

    /**
     * @var array<string, Collection<string, string>>
     */
    private array $memoizedPresents = [];

    /**
     * Meetings as a slug => heading map for admin dropdowns. Cached for 24 hours
     * to avoid the join + heading lookup on every render.
     *
     * Performance Optimization: Implements request-level memoization to avoid
     * redundant flexible cache lookups when referenced multiple times.
     *
     * @return Collection<string, string>
     */
    public function forAdminList(): Collection
    {
        if (isset($this->memoizedPresents[self::ADMIN_LIST_CACHE_KEY])) {
            return $this->memoizedPresents[self::ADMIN_LIST_CACHE_KEY];
        }

        return $this->memoizedPresents[self::ADMIN_LIST_CACHE_KEY] = Cache::flexible(self::ADMIN_LIST_CACHE_KEY, self::CACHE_TTL, function (): Collection {
            return Meeting::query()
                ->select(['id', 'slug', 'page_id'])
                ->with('page:id,heading')
                ->get()
                ->mapWithKeys(fn (Meeting $m) => [$m->slug => $m->page->heading ?? $m->slug]);
        });
    }

    /**
     * Clear the internal request-level memoization.
     */
    public function clearInternalCaches(): void
    {
        $this->memoizedPresents = [];
    }

    /**
     * Clear the external flexible caches.
     */
    public function forgetFlexibleCaches(): void
    {
        $this->forgetFlexible(self::ADMIN_LIST_CACHE_KEY);
    }

    /**
     * Internal helper to clear flexible cache and its created metadata.
     */
    private function forgetFlexible(string $key): void
    {
        Cache::forget($key);
        Cache::forget("illuminate:cache:flexible:created:{$key}");
    }
}
