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
     * @var array<string, mixed>
     */
    private array $memoizedPresents = [];

    /**
     * Meetings as a slug => heading map for admin dropdowns. Cached for 24 hours
     * to avoid the join + heading lookup on every render.
     *
     * @return Collection<string, string>
     */
    public function forAdminList(): Collection
    {
        if (isset($this->memoizedPresents['admin_list'])) {
            /** @var Collection<string, string> */
            return $this->memoizedPresents['admin_list'];
        }

        return $this->memoizedPresents['admin_list'] = Cache::flexible(self::ADMIN_LIST_CACHE_KEY, self::CACHE_TTL, function (): Collection {
            return Meeting::query()
                ->select(['id', 'slug', 'page_id'])
                ->with('page:id,heading')
                ->get()
                ->mapWithKeys(fn (Meeting $m) => [$m->slug => $m->page->heading ?? $m->slug]);
        });
    }

    /**
     * Clear all internal memoized caches.
     */
    public function clearInternalCaches(): void
    {
        $this->memoizedPresents = [];
    }

    /**
     * Clear the flexible cache keys from the external cache store.
     */
    public function forgetFlexibleCaches(): void
    {
        $this->forgetFlexible(self::ADMIN_LIST_CACHE_KEY);
    }

    /**
     * Clear a flexible cache key including its metadata key.
     */
    private function forgetFlexible(string $key): void
    {
        Cache::forget($key);
        Cache::forget("illuminate:cache:flexible:created:{$key}");
    }
}
