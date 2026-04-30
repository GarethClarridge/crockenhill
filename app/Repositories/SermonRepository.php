<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Enums\SermonContentType;
use App\Enums\SermonService;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Models\SermonScriptureFilter;
use App\Support\BibleCanon;
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
            ->select(['id', 'title', 'date', 'slug', 'service', 'preacher', 'preacher_id', 'series', 'reference', 'scripture_passage_id', 'duration', 'audio_file_path', 'video_file_path', 'transcript_file_path', 'thumbnail_file_path', 'thumbnail_generated_at', 'thumbnail_metadata', 'source_type', 'content_type', 'updated_at', 'meta_description', 'summary', 'show_summary'])
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
     * Normalize and validate raw sermon archive filter inputs against the Bible canon.
     *
     * Returns sanitized values; invalid book names and out-of-range chapters become null.
     *
     * @return array{book: string|null, chapter: int|null, preacherId: int|null, series: string|null}
     */
    public function normalizeArchiveFilters(
        BibleCanon $bibleCanon,
        string|int|null $book,
        string|int|null $chapter,
        string|int|null $preacherId,
        string|int|null $series,
    ): array {
        $book = is_string($book) && trim($book) !== '' ? trim($book) : null;
        $series = is_string($series) && trim($series) !== '' ? trim($series) : null;
        $preacherId = filter_var($preacherId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $chapter = filter_var($chapter, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;

        if ($book !== null && ! $bibleCanon->hasBook($book)) {
            $book = null;
        }

        if ($book === null) {
            $chapter = null;
        } elseif ($chapter !== null && $chapter > $bibleCanon->chaptersInBook($book)) {
            $chapter = null;
        }

        return compact('book', 'chapter', 'preacherId', 'series');
    }

    /**
     * Get the most recent sermons for archive-level JSON-LD generation.
     *
     * Why: `ItemList` schema doesn't require the full archive; loading every sermon
     * with relations on every request is wasteful at 700+ rows.
     *
     * @return Collection<int, Sermon>
     */
    public function getRecentSermonsForJsonLd(int $limit = 100): Collection
    {
        return Cache::flexible("sermons_jsonld_recent_{$limit}", [86400, 172800], function () use ($limit): Collection {
            return $this->publicBrowseQuery()
                ->limit($limit)
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
        Cache::forget('sermons_jsonld_recent_100');

        if ($model === null) {
            Cache::forget('sermon_scripture_books_all_all');
            $this->forgetScriptureChapterCaches();
        }

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
     * Get the cache key for a specific preacher's sermon listing.
     */
    private function preacherCacheKey(Preacher $preacher): string
    {
        $slug = (string) ($preacher->slug ?: Str::slug($preacher->name));

        return 'sermons_preacher_'.$slug;
    }

    private function forgetScriptureChapterCaches(): void
    {
        /** @var array<int, string> $knownBooks */
        $knownBooks = Cache::get('sermon_scripture_books_with_cached_chapters', []);

        foreach ($knownBooks as $book) {
            $bookSlug = Str::slug($book);
            Cache::forget("sermon_scripture_chapters_{$bookSlug}_all_all");
        }

        Cache::forget('sermon_scripture_books_with_cached_chapters');
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
     * Get distinct Bible books that have associated sermons, optionally filtered by preacher or series.
     *
     * Performance Optimization: Caches the book list for 24 hours using flexible cache.
     * If no preacher or series filters are applied, it avoids joining the sermons table.
     *
     * @return Collection<int, string>
     */
    public function getScriptureBooks(mixed $preacherId = null, mixed $series = null): Collection
    {
        $preacherId = (int) $preacherId ?: null;
        $series = filled($series) ? (string) $series : null;

        $cacheKey = 'sermon_scripture_books_'.($preacherId ?? 'all').'_'.($series ? Str::slug($series) : 'all');

        return Cache::flexible($cacheKey, [86400, 172800], function () use ($preacherId, $series): Collection {
            $query = SermonScriptureFilter::query();

            if ($preacherId === null && $series === null) {
                // Entries are only created for ContentType::Sermon, so we can skip the join
                return $query->select('bible_book')
                    ->distinct()
                    ->pluck('bible_book');
            }

            return $query->join('sermons', 'sermons.id', '=', 'sermon_scripture_filters.sermon_id')
                ->where('sermons.content_type', SermonContentType::Sermon)
                ->when($preacherId, fn (Builder $q) => $q->where('sermons.preacher_id', $preacherId))
                ->when($series, fn (Builder $q) => $q->where('sermons.series', $series))
                ->select('bible_book')
                ->distinct()
                ->pluck('bible_book');
        });
    }

    /**
     * Get distinct chapters for a Bible book that have associated sermons, optionally filtered by preacher or series.
     *
     * Performance Optimization: Caches the chapter list for 24 hours using flexible cache.
     * If no preacher or series filters are applied, it avoids joining the sermons table.
     *
     * @return Collection<int, int>
     */
    public function getScriptureChapters(string $book, mixed $preacherId = null, mixed $series = null): Collection
    {
        $preacherId = (int) $preacherId ?: null;
        $series = filled($series) ? (string) $series : null;

        /** @var array<int, string> $knownBooks */
        $knownBooks = Cache::get('sermon_scripture_books_with_cached_chapters', []);
        if (! in_array($book, $knownBooks, true)) {
            $knownBooks[] = $book;
            Cache::put('sermon_scripture_books_with_cached_chapters', $knownBooks, 172800);
        }

        $cacheKey = 'sermon_scripture_chapters_'.Str::slug($book).'_'.($preacherId ?? 'all').'_'.($series ? Str::slug($series) : 'all');

        return Cache::flexible($cacheKey, [86400, 172800], function () use ($book, $preacherId, $series): Collection {
            $query = SermonScriptureFilter::query()->where('bible_book', $book);

            if ($preacherId === null && $series === null) {
                return $query->select('bible_chapter')
                    ->distinct()
                    ->orderBy('bible_chapter')
                    ->pluck('bible_chapter');
            }

            return $query->join('sermons', 'sermons.id', '=', 'sermon_scripture_filters.sermon_id')
                ->where('sermons.content_type', SermonContentType::Sermon)
                ->when($preacherId, fn (Builder $q) => $q->where('sermons.preacher_id', $preacherId))
                ->when($series, fn (Builder $q) => $q->where('sermons.series', $series))
                ->select('bible_chapter')
                ->distinct()
                ->orderBy('bible_chapter')
                ->pluck('bible_chapter');
        });
    }

    /**
     * Clear the cached scripture book and chapter lists for a specific sermon.
     *
     * Performance Optimization: Invalidates caches for both current and original values
     * to ensure filter options stay in sync when a sermon's preacher, series, or
     * scripture reference is modified.
     */
    public function clearScriptureChapterCaches(Sermon $sermon): void
    {
        // Always clear the global (no-filter) book list
        Cache::forget('sermon_scripture_books_all_all');

        // Extract all possible values that could be cached
        $preacherIds = array_filter(array_unique([
            (int) $sermon->preacher_id ?: null,
            (int) $sermon->getOriginal('preacher_id') ?: null,
        ]));

        $seriesNames = array_filter(array_unique([
            $sermon->series ?: null,
            $sermon->getOriginal('series') ?: null,
        ]));

        $seriesSlugs = array_map(fn (string $s) => Str::slug($s), $seriesNames);

        // Clear book list caches for all combinations of preacher and series
        foreach ($preacherIds as $id) {
            Cache::forget("sermon_scripture_books_{$id}_all");
        }

        foreach ($seriesSlugs as $slug) {
            Cache::forget("sermon_scripture_books_all_{$slug}");
            foreach ($preacherIds as $id) {
                Cache::forget("sermon_scripture_books_{$id}_{$slug}");
            }
        }

        // Resolve all Bible books associated with this sermon (current and previous)
        // to ensure all relevant chapter caches are invalidated. We parse references
        // directly to handle new, deleted, or updated states robustly.
        $indexService = app(\App\Services\SermonScriptureFilterIndexService::class);
        $references = array_filter(array_unique([
            (string) $sermon->reference ?: null,
            (string) $sermon->getOriginal('reference') ?: null,
        ]));

        $books = [];
        foreach ($references as $ref) {
            foreach ($indexService->entriesForReference($ref) as $entry) {
                $books[] = $entry['bible_book'];
            }
        }

        // We also check the relationship to catch current or deleted filters.
        foreach ($sermon->scriptureFilters()->distinct()->pluck('bible_book') as $book) {
            $books[] = (string) $book;
        }

        $books = array_unique($books);

        foreach ($books as $book) {
            $bookSlug = Str::slug($book);
            Cache::forget("sermon_scripture_chapters_{$bookSlug}_all_all");

            foreach ($preacherIds as $id) {
                Cache::forget("sermon_scripture_chapters_{$bookSlug}_{$id}_all");
            }

            foreach ($seriesSlugs as $slug) {
                Cache::forget("sermon_scripture_chapters_{$bookSlug}_all_{$slug}");
                foreach ($preacherIds as $id) {
                    Cache::forget("sermon_scripture_chapters_{$bookSlug}_{$id}_{$slug}");
                }
            }
        }
    }
}
