<?php

declare(strict_types=1);

namespace App\Services\Public;

use App\Models\Preacher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class PreacherListCache
{
    public const ADMIN_LIST_CACHE_KEY = 'admin_preacher_list';

    public const PUBLIC_LIST_CACHE_KEY = 'public_preacher_list';

    private const CACHE_TTL = [300, 86400];

    /**
     * Active preachers as an id => name map, sorted by name.
     * Cached for 24 hours to avoid recomputing in admin dropdowns.
     *
     * @return Collection<int, string>
     */
    public function forAdminList(): Collection
    {
        return Cache::flexible(self::ADMIN_LIST_CACHE_KEY, self::CACHE_TTL, function (): Collection {
            return Preacher::query()->active()->orderBy('name')->pluck('name', 'id');
        });
    }

    /**
     * Active preachers with sermon counts, ordered by sermon count then name,
     * for the public preachers index. Cached for 24 hours.
     *
     * @return EloquentCollection<int, Preacher>
     */
    public function forPublicList(): EloquentCollection
    {
        return Cache::flexible(self::PUBLIC_LIST_CACHE_KEY, self::CACHE_TTL, function (): EloquentCollection {
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
}
