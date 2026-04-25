<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Enums\SermonContentType;
use App\Enums\SermonService;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Models\SermonScriptureFilter;
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
            ->select(['id', 'title', 'date', 'slug', 'service', 'preacher', 'preacher_id', 'series', 'reference', 'scripture_passage_id', 'duration', 'audio_file_path', 'video_file_path', 'thumbnail_file_path', 'thumbnail_generated_at', 'thumbnail_metadata', 'source_type', 'content_type', 'updated_at', 'meta_description', 'summary', 'show_summary', 'filetype'])
            ->with([
                'preacherProfile:id,name,slug,image_path',
                'scripturePassage:id,display_reference,normalized_reference',
            ]);
    }

    /**
     * Build the ordered public sermon query for the canonical archive page.
     *
     * @return Builder<Sermon>
     */
    public function publicBrowseQuery(
        ?string $book = null,
        ?int $chapter = null,
        ?int $preacherId = null,
        ?string $series = null,
    ): Builder {
        $query = $this->publicSermonQuery()
            ->when($preacherId, fn (Builder $builder): Builder => $builder->where('preacher_id', $preacherId))
            ->when($series, fn (Builder $builder): Builder => $builder->where('series', $series));

        if ($book !== null) {
            $query->whereHas('scriptureFilters', function (Builder $builder) use ($book, $chapter): void {
                $builder->where('bible_book', $book);

                if ($chapter !== null) {
                    $builder->where('bible_chapter', $chapter);
                }
            });
        }

        return $query
            ->orderBy('date', 'desc')
            ->orderBy('service', 'asc')
            ->orderBy('id', 'desc');
    }

    /**
     * Get the latest sermons grouped by date.
     *
     * @return Collection<string, Collection<int, Sermon>>
     */
    public function getLatestSermons(): Collection
    {
        return Cache::flexible('latest_sermons', [86400, 172800], function (): Collection {
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
        return Cache::flexible('all_sermons', [86400, 172800], function (): Collection {
            return $this->publicSermonQuery()
                ->orderBy('date', 'desc')
                ->orderBy('service', 'asc')
                ->get()
                ->groupBy(function (Sermon $sermon): string {
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
        return Cache::flexible('sermons_series_'.Str::slug($seriesName), [86400, 172800], function () use ($seriesName): Collection {
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
        return Cache::flexible($this->preacherCacheKey($preacher), [86400, 172800], function () use ($preacher): Collection {
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
    public function getSermonsByService(SermonService $service): Collection
    {
        return Cache::flexible("sermons_service_{$service->value}", [86400, 172800], function () use ($service): Collection {
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
        Cache::forget('sermon_scripture_books');

        if ($model instanceof Sermon) {
            $this->clearScriptureChapterCaches($model);

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
     * Generate a unique URL slug for the sermon, optionally excluding a specific sermon ID.
     */
    public function generateUniqueSlug(string $title, ?int $excludeSermonId = null): string
    {
        $baseSlug = Str::slug($title);
        $slug = $baseSlug;
        $counter = 1;

        // Ensure slug is unique
        $query = Sermon::where('slug', $slug);
        if ($excludeSermonId !== null) {
            $query->where('id', '!=', $excludeSermonId);
        }

        while ($query->clone()->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
            $query = Sermon::where('slug', $slug);
            if ($excludeSermonId !== null) {
                $query->where('id', '!=', $excludeSermonId);
            }
        }

        return $slug;
    }

    /**
     * Clear scripture chapter caches for a specific sermon's bible references.
     */
    private function clearScriptureChapterCaches(Sermon $sermon): void
    {
        // Clear caches for all books associated with this sermon via scripture filters
        $books = $sermon->scriptureFilters()->pluck('bible_book')->unique();

        foreach ($books as $book) {
            Cache::forget("sermon_scripture_chapters_{$book}");
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
        return Cache::flexible('sermon_series', [86400, 172800], function (): array {
            $series = $this->getExistingSeries();
            sort($series);

            return $series;
        });
    }

    /**
     * Get distinct bible books that have associated sermons.
     *
     * Performance Optimization: Caches the book list for 24 hours using flexible cache.
     * Optimizes the query to avoid joining the 'sermons' table when filters are null.
     *
     * @return array<int, string>
     */
    public function getScriptureBooks(?int $preacherId = null, ?string $series = null): array
    {
        if ($preacherId === null && $series === null) {
            return Cache::flexible('sermon_scripture_books', [86400, 172800], function (): array {
                return SermonScriptureFilter::query()
                    ->select('bible_book')
                    ->distinct()
                    ->pluck('bible_book')
                    ->all();
            });
        }

        return SermonScriptureFilter::query()
            ->join('sermons', 'sermons.id', '=', 'sermon_scripture_filters.sermon_id')
            ->where('sermons.content_type', SermonContentType::Sermon->value)
            ->when($preacherId, fn (Builder $query): Builder => $query->where('sermons.preacher_id', $preacherId))
            ->when($series, fn (Builder $query): Builder => $query->where('sermons.series', $series))
            ->select('bible_book')
            ->distinct()
            ->pluck('bible_book')
            ->all();
    }

    /**
     * Get distinct bible chapters for a book that have associated sermons.
     *
     * Performance Optimization: Caches the chapter list per book for 24 hours using flexible cache.
     *
     * @return array<int, int>
     */
    public function getScriptureChapters(string $book, ?int $preacherId = null, ?string $series = null): array
    {
        if ($preacherId === null && $series === null) {
            return Cache::flexible("sermon_scripture_chapters_{$book}", [86400, 172800], function () use ($book): array {
                return SermonScriptureFilter::query()
                    ->where('bible_book', $book)
                    ->select('bible_chapter')
                    ->distinct()
                    ->orderBy('bible_chapter')
                    ->pluck('bible_chapter')
                    ->map(fn (mixed $chapter): int => (int) $chapter)
                    ->all();
            });
        }

        return SermonScriptureFilter::query()
            ->join('sermons', 'sermons.id', '=', 'sermon_scripture_filters.sermon_id')
            ->where('sermons.content_type', SermonContentType::Sermon->value)
            ->where('sermon_scripture_filters.bible_book', $book)
            ->when($preacherId, fn (Builder $query): Builder => $query->where('sermons.preacher_id', $preacherId))
            ->when($series, fn (Builder $query): Builder => $query->where('sermons.series', $series))
            ->select('bible_chapter')
            ->distinct()
            ->orderBy('bible_chapter')
            ->pluck('bible_chapter')
            ->map(fn (mixed $chapter): int => (int) $chapter)
            ->all();
    }
}
