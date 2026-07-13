<?php

declare(strict_types=1);

namespace App\Services\Public;

use App\Enums\SermonContentType;
use App\Enums\SermonService;
use App\Models\Builders\SermonBuilder;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Models\SermonScriptureFilter;
use App\Services\Scripture\SermonScriptureFilterIndexService;
use App\Support\BibleCanon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SermonRepository
{
    /** @var array<string, mixed> */
    private array $memoizedPresents = [];

    /** @var array<string, true> */
    private array $computed = [];

    public function __construct(
        private readonly SermonScriptureFilterIndexService $indexService,
    ) {}

    /**
     * Build the base query for public sermon listings and browse pages.
     *
     * Performance Optimization: Limits retrieved columns to the set required by
     * SermonViewPresenter and SermonExposurePolicy to minimize memory usage and
     * prevent N+1 lazy-loading of media metadata or related profile images.
     */
    public function basePublicSermonQuery(?SermonContentType $contentType = null): SermonBuilder
    {
        return Sermon::query()
            ->when($contentType, fn (Builder $q) => $q->where('content_type', $contentType))
            ->select([
                'id',
                'title',
                'date',
                'slug',
                'service',
                'preacher',
                'preacher_id',
                'series',
                'reference',
                'scripture_passage_id',
                'duration',
                'filetype',
                'audio_file_path',
                'video_file_path',
                'transcript_file_path',
                'thumbnail_file_path',
                'thumbnail_generated_at',
                'thumbnail_metadata',
                'source_type',
                'content_type',
                'updated_at',
                'meta_description',
                'summary',
                'show_summary',
                'video_quality_status',
                'video_visibility_override',
            ])
            ->with([
                'preacherProfile:id,name,slug,image_path',
                'scripturePassage:id,display_reference,normalized_reference',
            ]);
    }

    /**
     * Resolve a series name from its URL slug.
     *
     * Performance Optimization: Uses the cached series list to find a matching slug
     * without additional database queries.
     */
    public function resolveSeriesNameFromSlug(string $slug): ?string
    {
        $series = $this->getSeriesForDisplay();

        foreach ($series as $name) {
            if (Str::slug($name) === $slug) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Build the base query for public sermon listings (ContentType::Sermon).
     */
    public function publicSermonQuery(): SermonBuilder
    {
        return $this->basePublicSermonQuery(SermonContentType::Sermon);
    }

    /**
     * Build the ordered public sermon query for the canonical archive page.
     */
    public function publicBrowseQuery(
        ?string $book = null,
        ?int $chapter = null,
        ?int $preacherId = null,
        ?string $series = null,
    ): SermonBuilder {
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
     * Get sermons for a specific series.
     *
     * Performance Optimization: Implements request-level memoization to avoid redundant
     * flexible cache lookups and hits within a single request cycle.
     *
     * @return Collection<int, Sermon>
     */
    public function getSermonsBySeries(string $seriesName): Collection
    {
        return $this->rememberFlexible('sermons_series_'.Str::slug($seriesName), [86400, 172800], function () use ($seriesName): Collection {
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
     * cache to reduce redundant DB queries when viewing preacher profiles. Implements
     * request-level memoization to avoid redundant cache hits within a single request.
     *
     * @return Collection<int, Sermon>
     */
    public function getSermonsByPreacher(Preacher $preacher): Collection
    {
        return $this->rememberFlexible($this->preacherCacheKey($preacher), [86400, 172800], function () use ($preacher): Collection {
            return $this->publicSermonQuery()
                ->where('preacher_id', $preacher->id)
                ->orderBy('date', 'desc')
                ->get();
        });
    }

    /**
     * Get sermons for a specific service.
     *
     * Performance Optimization: Implements request-level memoization to avoid redundant
     * flexible cache lookups and hits within a single request cycle.
     *
     * @return Collection<int, Sermon>
     */
    public function getSermonsByService(SermonService $service): Collection
    {
        return $this->rememberFlexible("sermons_service_{$service->value}", [86400, 172800], function () use ($service): Collection {
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
        $book = filled($book) ? trim((string) $book) : null;
        $series = filled($series) ? trim((string) $series) : null;
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
     * Look up an existing Sermon record by date, service, and content type.
     *
     * Content type is part of the match key because the sermons table holds
     * both main sermons and children's talks for the same `(date, service)`
     * pair, distinguished only by `content_type`.
     */
    public function findByDateAndServiceAndContentType(
        Carbon|string $date,
        SermonService $service,
        SermonContentType $contentType,
    ): ?Sermon {
        $dateString = $date instanceof Carbon ? $date->toDateString() : $date;

        return Sermon::query()
            ->where('date', $dateString)
            ->where('service', $service)
            ->where('content_type', $contentType)
            ->first();
    }

    /**
     * Generate a unique URL slug for the sermon, optionally excluding a specific sermon ID.
     */
    public function generateUniqueSlug(string $title, ?int $excludeSermonId = null): string
    {
        $baseSlug = Str::slug($title);
        $slug = $baseSlug;
        $counter = 1;

        $query = Sermon::query()
            ->when($excludeSermonId, fn (Builder $q) => $q->where('id', '!=', $excludeSermonId));

        while ($query->clone()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
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
        return $this->rememberFlexible('sermon_series', [86400, 172800], function (): array {
            return $this->getExistingSeries();
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

        return $this->rememberFlexible($cacheKey, [86400, 172800], function () use ($preacherId, $series): Collection {
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

        $cacheKey = 'sermon_scripture_chapters_'.Str::slug($book).'_'.($preacherId ?? 'all').'_'.($series ? Str::slug($series) : 'all');

        return $this->rememberFlexible($cacheKey, [86400, 172800], function () use ($book, $preacherId, $series): Collection {
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
        $this->forgetFlexible('sermon_scripture_books_all_all');

        // Extract all possible values that could be cached
        $preacherIds = collect([$sermon->preacher_id, $sermon->getOriginal('preacher_id')])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $seriesSlugs = collect([$sermon->series, $sermon->getOriginal('series')])
            ->filter()
            ->map(fn (string $s) => Str::slug($s))
            ->unique()
            ->values()
            ->all();

        $this->forgetPreacherAndSeriesCaches($preacherIds, $seriesSlugs);

        // Resolve all Bible books associated with this sermon (current and previous)
        // to ensure all relevant chapter caches are invalidated. We parse references
        // directly to handle new, deleted, or updated states robustly.
        $books = collect([(string) $sermon->reference ?: null, (string) $sermon->getOriginal('reference') ?: null])
            ->filter()
            ->unique()
            ->flatMap(fn (string $ref) => $this->indexService->entriesForReference($ref))
            ->pluck('bible_book')
            ->merge($sermon->scriptureFilters()->distinct()->pluck('bible_book'))
            ->map(fn ($book) => (string) $book)
            ->unique()
            ->values()
            ->all();

        $this->forgetBookAndChapterCaches($books, $preacherIds, $seriesSlugs);
    }

    /**
     * Clear scripture book list caches for all combinations of preacher and series.
     *
     * @param  array<int, int>  $preacherIds
     * @param  array<int, string>  $seriesSlugs
     */
    private function forgetPreacherAndSeriesCaches(array $preacherIds, array $seriesSlugs): void
    {
        collect($preacherIds)->each(fn ($id) => $this->forgetFlexible("sermon_scripture_books_{$id}_all"));

        collect($seriesSlugs)->each(function (string $slug) use ($preacherIds): void {
            $this->forgetFlexible("sermon_scripture_books_all_{$slug}");
            collect($preacherIds)->each(fn ($id) => $this->forgetFlexible("sermon_scripture_books_{$id}_{$slug}"));
        });
    }

    /**
     * Clear scripture chapter list caches for all combinations of Bible book, preacher, and series.
     *
     * @param  array<int, string>  $books
     * @param  array<int, int>  $preacherIds
     * @param  array<int, string>  $seriesSlugs
     */
    private function forgetBookAndChapterCaches(array $books, array $preacherIds, array $seriesSlugs): void
    {
        $pOptions = collect([...array_map(fn ($id) => (string) $id, $preacherIds), 'all']);
        $sOptions = collect([...$seriesSlugs, 'all']);

        collect($books)->each(function (string $book) use ($pOptions, $sOptions): void {
            $bookSlug = Str::slug($book);

            $pOptions->crossJoin($sOptions)->each(function ($pair) use ($bookSlug): void {
                $this->forgetFlexible("sermon_scripture_chapters_{$bookSlug}_{$pair[0]}_{$pair[1]}");
            });
        });
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

    /**
     * Clear all cached sermon listings.
     */
    public function clearListingCaches(Sermon|Preacher|null $model = null): void
    {
        $this->forgetFlexible('sermon_series');
        $this->forgetFlexible('sermon_scripture_books_all_all');

        $this->clearInternalCaches();

        if ($model instanceof Sermon) {
            $this->clearScriptureChapterCaches($model);

            // Invalidate for current and original series
            collect([$model->series, $model->getOriginal('series')])
                ->filter()
                ->map(fn (string $s) => Str::slug($s))
                ->unique()
                ->values()
                ->each(fn (string $slug) => $this->forgetFlexible('sermons_series_'.$slug));

            // Invalidate for current and original service
            collect([$model->service, $model->getOriginal('service')])
                ->map(fn ($s) => $s instanceof SermonService ? $s->value : $s)
                ->filter()
                ->unique()
                ->values()
                ->each(fn ($serviceValue) => $this->forgetFlexible('sermons_service_'.$serviceValue));

            // Invalidate for current and original preacher
            collect([$model->preacher_id, $model->getOriginal('preacher_id')])
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->unique()
                ->values()
                ->each(function (int $id): void {
                    $preacher = Preacher::query()->find($id);
                    if ($preacher) {
                        $this->forgetFlexible($this->preacherCacheKey($preacher));
                    }
                });
        }

        if ($model instanceof Preacher) {
            $this->forgetFlexible($this->preacherCacheKey($model));
        }
    }

    /**
     * Clear a flexible cache key including its metadata key.
     */
    private function forgetFlexible(string $key): void
    {
        Cache::forget($key);
        Cache::forget("illuminate:cache:flexible:created:{$key}");
    }

    /**
     * Get a value from the request-level memoization cache, or resolve it
     * through a flexible cache.
     *
     * @template T
     *
     * @param  array{int, int}  $ttl  Array of [flexible_seconds, stale_seconds]
     * @param  \Closure(): T  $callback
     * @return T
     */
    private function rememberFlexible(string $cacheKey, array $ttl, \Closure $callback): mixed
    {
        if (isset($this->computed[$cacheKey])) {
            /** @var T */
            return $this->memoizedPresents[$cacheKey];
        }

        /** @var T $value */
        $value = Cache::flexible($cacheKey, $ttl, $callback);

        $this->computed[$cacheKey] = true;
        $this->memoizedPresents[$cacheKey] = $value;

        return $value;
    }
}
