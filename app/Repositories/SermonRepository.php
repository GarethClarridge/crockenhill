<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Preacher;
use App\Models\Sermon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SermonRepository
{
    /**
     * Build the base query for public sermon listings and browse pages.
     *
     * @return Builder<Sermon>
     */
    public function publicSermonQuery(): Builder
    {
        return Sermon::query()
            ->whereSermon()
            ->select(['id', 'title', 'date', 'slug', 'service', 'preacher', 'preacher_id', 'series', 'reference', 'thumbnail_file_path', 'thumbnail_metadata', 'source_type'])
            ->with('preacherProfile:id,name,slug');
    }

    /**
     * Get the latest sermons grouped by date.
     *
     * @return Collection<string, Collection<int, Sermon>>
     */
    public function getLatestSermons(): Collection
    {
        return Cache::flexible('latest_sermons', [86400, 172800], function () {
            $distinct_dates = Sermon::query()
                ->whereSermon()
                ->select('date')
                ->distinct()
                ->orderBy('date', 'desc')
                ->limit(6)
                ->pluck('date');

            if ($distinct_dates->isEmpty()) {
                return collect();
            }

            return $this->publicSermonQuery()
                ->whereIn('date', $distinct_dates)
                ->orderBy('date', 'desc')
                ->orderBy('service', 'asc')
                ->get()
                ->groupBy(fn ($sermon) => $sermon->date->format('Y-m-d'));
        });
    }

    /**
     * Get all sermons grouped by date.
     *
     * @return Collection<string, Collection<int, Sermon>>
     */
    public function getAllSermons(): Collection
    {
        return Cache::flexible('all_sermons', [86400, 172800], function () {
            return $this->publicSermonQuery()
                ->orderBy('date', 'desc')
                ->orderBy('service', 'asc')
                ->get()
                ->groupBy(function ($sermon) {
                    return $sermon->date->format('Y-m-d');
                });
        });
    }

    /**
     * Get sermons for a specific series.
     *
     * @return Collection<int, Sermon>
     */
    public function getSermonsBySeries(string $seriesName): Collection
    {
        return Cache::flexible('sermons_series_'.Str::slug($seriesName), [86400, 172800], function () use ($seriesName) {
            return $this->publicSermonQuery()
                ->where('series', $seriesName)
                ->orderBy('date', 'desc')
                ->get();
        });
    }

    /**
     * Get sermons for a specific preacher.
     *
     * Performance Optimization: Caches the preacher's sermon listing for 24 hours using flexible
     * cache to reduce redundant DB queries when viewing preacher profiles.
     *
     * @return Collection<int, Sermon>
     */
    public function getSermonsByPreacher(Preacher $preacher): Collection
    {
        return Cache::flexible($this->preacherCacheKey($preacher), [86400, 172800], function () use ($preacher) {
            return $this->publicSermonQuery()
                ->where('preacher_id', $preacher->id)
                ->orderBy('date', 'desc')
                ->get();
        });
    }

    /**
     * Get sermons for a specific service.
     *
     * @return Collection<int, Sermon>
     */
    public function getSermonsByService(string $service): Collection
    {
        return Cache::flexible("sermons_service_{$service}", [86400, 172800], function () use ($service) {
            return $this->publicSermonQuery()
                ->where('service', $service)
                ->orderBy('date', 'desc')
                ->get();
        });
    }

    /**
     * Clear all cached sermon listings.
     */
    public function clearListingCaches(Sermon|Preacher|null $model = null): void
    {
        Cache::forget('latest_sermons');
        Cache::forget('all_sermons');
        Cache::forget('sermon_series');

        if ($model instanceof Sermon) {
            if ($model->series) {
                Cache::forget('sermons_series_'.Str::slug($model->series));
            }
            if ($model->service) {
                $serviceValue = $model->service->value;
                Cache::forget('sermons_service_'.$serviceValue);
            }
            if ($model->preacher_id) {
                // Eager load preacherProfile if not loaded to get the key for cache invalidation
                $model->loadMissing('preacherProfile');
                if ($model->preacherProfile) {
                    Cache::forget($this->preacherCacheKey($model->preacherProfile));
                }
            }
        }

        if ($model instanceof Preacher) {
            Cache::forget($this->preacherCacheKey($model));
        }
    }

    /**
     * Get the cache key for a specific preacher's sermon listing.
     */
    private function preacherCacheKey(Preacher $preacher): string
    {
        $slug = (string) ($preacher->slug ?: Str::slug($preacher->name));

        return 'sermons_preacher_'.$slug;
    }

    /**
     * Get all distinct sermon series from database.
     *
     * @return array<int, string>
     */
    public function getExistingSeries(): array
    {
        try {
            return Sermon::query()
                ->whereSermon()
                ->whereNotNull('series')
                ->where('series', '!=', '')
                ->distinct()
                ->orderBy('series')
                ->pluck('series')
                ->all();
        } catch (\Exception $e) {
            Log::warning('Failed to retrieve existing series', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Get all distinct sermon series sorted alphabetically for display in UI.
     *
     * Performance Optimization: Caches the series list for 24 hours using flexible cache
     * to reduce redundant distinct DB queries on listing and admin pages.
     *
     * @return array<int, string>
     */
    public function getSeriesForDisplay(): array
    {
        return Cache::flexible('sermon_series', [86400, 172800], function () {
            $series = $this->getExistingSeries();
            sort($series);

            return $series;
        });
    }
}
