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

    /** @var Collection<int, string>|null */
    private ?Collection $memoizedAdminList = null;

    /** @var EloquentCollection<int, Preacher>|null */
    private ?EloquentCollection $memoizedPublicList = null;

    /**
     * Active preachers as an id => name map, sorted by name.
     * Cached for 24 hours to avoid recomputing in admin dropdowns.
     *
     * Performance Optimization: Memoizes the result for the duration of the
     * request to avoid redundant flexible cache lookups when building
     * multiple dropdowns or listings.
     *
     * @return Collection<int, string>
     */
    public function forAdminList(): Collection
    {
        if ($this->memoizedAdminList !== null) {
            return $this->memoizedAdminList;
        }

        return $this->memoizedAdminList = Cache::flexible(self::ADMIN_LIST_CACHE_KEY, self::CACHE_TTL, function (): Collection {
            return Preacher::query()->active()->orderBy('name')->pluck('name', 'id');
        });
    }

    /**
     * Active preachers with sermon counts, ordered by sermon count then name,
     * for the public preachers index. Cached for 24 hours.
     *
     * Performance Optimization: Memoizes the result for the duration of the
     * request to avoid redundant flexible cache lookups when building
     * preacher archive views and JSON-LD structured data.
     *
     * @return EloquentCollection<int, Preacher>
     */
    public function forPublicList(): EloquentCollection
    {
        if ($this->memoizedPublicList !== null) {
            return $this->memoizedPublicList;
        }

        return $this->memoizedPublicList = Cache::flexible(self::PUBLIC_LIST_CACHE_KEY, self::CACHE_TTL, function (): EloquentCollection {
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
     * Clear all internal memoization caches.
     * Useful for long-running processes or tests.
     */
    public function clearInternalCaches(): void
    {
        $this->memoizedAdminList = null;
        $this->memoizedPublicList = null;
    }
}
