<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Meeting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class MeetingListCache
{
    public const ADMIN_LIST_CACHE_KEY = 'admin_meeting_list';

    private const CACHE_TTL = [86400, 172800];

    /** @var array<string, mixed> */
    private array $memoizedPresents = [];

    /** @var array<string, true> */
    private array $computed = [];

    /**
     * Meetings as a slug => heading map for admin dropdowns. Cached for 24 hours
     * to avoid the join + heading lookup on every render.
     *
     * @return Collection<string, string>
     */
    public function forAdminList(): Collection
    {
        if (isset($this->computed['admin_list'])) {
            /** @var Collection<string, string> */
            return $this->memoizedPresents['admin_list'];
        }

        $list = Cache::flexible(self::ADMIN_LIST_CACHE_KEY, self::CACHE_TTL, function (): Collection {
            return Meeting::query()
                ->select(['id', 'slug', 'page_id'])
                ->with('page:id,heading')
                ->get()
                ->mapWithKeys(fn (Meeting $m) => [$m->slug => $m->page->heading ?? $m->slug]);
        });

        $this->computed['admin_list'] = true;

        return $this->memoizedPresents['admin_list'] = $list;
    }

    /**
     * Clear all internal memoization caches.
     * Useful for long-running processes or tests.
     */
    public function clearInternalCaches(): void
    {
        $this->memoizedPresents = [];
        $this->computed = [];
    }
}
