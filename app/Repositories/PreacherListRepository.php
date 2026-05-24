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

    /** @var array<string, mixed> */
    private array $memoizedPresents = [];

    /** @var array<string, true> */
    private array $computed = [];

    /**
     * Active preachers as an id => name map, sorted by name.
     * Cached for 24 hours to avoid recomputing in admin dropdowns.
     *
     * @return Collection<int, string>
     */
    public function forAdminList(): Collection
    {
        if (isset($this->computed['admin_list'])) {
            /** @var Collection<int, string> */
            return $this->memoizedPresents['admin_list'];
        }

        $list = Cache::flexible(self::ADMIN_LIST_CACHE_KEY, self::CACHE_TTL, function (): Collection {
            return Preacher::query()->active()->orderBy('name')->pluck('name', 'id');
        });

        $this->computed['admin_list'] = true;

        return $this->memoizedPresents['admin_list'] = $list;
    }

    /**
     * Active preachers with sermon counts, ordered by sermon count then name,
     * for the public preachers index. Cached for 24 hours.
     *
     * @return EloquentCollection<int, Preacher>
     */
    public function forPublicList(): EloquentCollection
    {
        if (isset($this->computed['public_list'])) {
            /** @var EloquentCollection<int, Preacher> */
            return $this->memoizedPresents['public_list'];
        }

        $list = Cache::flexible(self::PUBLIC_LIST_CACHE_KEY, self::CACHE_TTL, function (): EloquentCollection {
            return Preacher::query()->active()
                ->select(['id', 'name', 'slug', 'image_path'])
                ->withCount([
                    'sermons' => fn (Builder $query): Builder => $query->whereSermon(),
                ])
                ->orderByDesc('sermons_count')
                ->orderBy('name')
                ->get();
        });

        $this->computed['public_list'] = true;

        return $this->memoizedPresents['public_list'] = $list;
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
