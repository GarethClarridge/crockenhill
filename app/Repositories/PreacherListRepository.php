<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Preacher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class PreacherListRepository
{
    public const ADMIN_LIST_CACHE_KEY = 'admin_preacher_list';

    public const PUBLIC_LIST_CACHE_KEY = 'public_preacher_list';

    private const CACHE_TTL = [86400, 172800];

    /**
     * @var array<string, Collection<int, string>|EloquentCollection<int, Preacher>>
     */
    private array $memoizedPresents = [];

    /**
     * Active preachers as an id => name map, sorted by name.
     * Cached for 24 hours to avoid recomputing in admin dropdowns.
     *
     * Performance Optimization: Implements request-level memoization to avoid
     * redundant flexible cache lookups when referenced multiple times.
     *
     * @return Collection<int, string>
     */
    public function forAdminList(): Collection
    {
        if (isset($this->memoizedPresents[self::ADMIN_LIST_CACHE_KEY])) {
            /** @var Collection<int, string> */
            return $this->memoizedPresents[self::ADMIN_LIST_CACHE_KEY];
        }

        return $this->memoizedPresents[self::ADMIN_LIST_CACHE_KEY] = Cache::flexible(self::ADMIN_LIST_CACHE_KEY, self::CACHE_TTL, function (): Collection {
            return Preacher::query()->active()->orderBy('name')->pluck('name', 'id');
        });
    }

    /**
     * Active preachers with sermon counts, ordered by sermon count then name,
     * for the public preachers index. Cached for 24 hours.
     *
     * Performance Optimization: Implements request-level memoization to avoid
     * redundant flexible cache lookups when referenced multiple times.
     *
     * @return EloquentCollection<int, Preacher>
     */
    public function forPublicList(): EloquentCollection
    {
        if (isset($this->memoizedPresents[self::PUBLIC_LIST_CACHE_KEY])) {
            /** @var EloquentCollection<int, Preacher> */
            return $this->memoizedPresents[self::PUBLIC_LIST_CACHE_KEY];
        }

        return $this->memoizedPresents[self::PUBLIC_LIST_CACHE_KEY] = Cache::flexible(self::PUBLIC_LIST_CACHE_KEY, self::CACHE_TTL, function (): EloquentCollection {
            return Preacher::query()->active()
                ->select(['id', 'name', 'slug', 'image_path'])
                ->withCount([
                    'sermons' => fn (Builder $query): Builder => $query->whereSermon(),
                ])
                ->orderByDesc('sermons_count')
                ->orderBy('name')
                ->get();
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
        $this->forgetFlexible(self::PUBLIC_LIST_CACHE_KEY);
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
