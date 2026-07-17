<?php

declare(strict_types=1);

namespace App\Services\Public;

use App\Enums\SermonContentType;
use App\Enums\SermonService;
use App\Models\Builders\SermonBuilder;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Models\SermonScriptureFilter;
use App\Support\FlexibleCache;
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
        foreach ($this->getSeriesForDisplay() as $name) {
            if (Str::slug($name) === $slug) {
                return $name;
            }
        }

        foreach ($this->getExistingSeries() as $name) {
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
     * @return Collection<int, Sermon>
     */
    public function getSermonsBySeries(string $seriesName): Collection
    {
        return Cache::flexible('sermons_series_'.Str::slug($seriesName), [300, 86400], function () use ($seriesName): Collection {
            return $this->publicSermonQuery()
                ->where('series', $seriesName)
                ->orderBy('date', 'desc')
                ->get();
        });
    }

    /**
     * Get sermons for a specific preacher.
     *
     * Performance Optimization: Caches the preacher's sermon listing using flexible
     * cache to reduce redundant DB queries when viewing preacher profiles.
     *
     * @return Collection<int, Sermon>
     */
    public function getSermonsByPreacher(Preacher $preacher): Collection
    {
        return Cache::flexible($this->preacherCacheKey($preacher), [300, 86400], function () use ($preacher): Collection {
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
        return Cache::flexible("sermons_service_{$service->value}", [300, 86400], function () use ($service): Collection {
            return $this->publicSermonQuery()
                ->where('service', $service)
                ->orderBy('date', 'desc')
                ->get();
        });
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
     * Forget the cached public listings this sermon can appear in.
     *
     * Ordinary edits rely on TTL freshness; this targeted eviction exists for
     * exposure transitions (deletion, reclassification, video hiding), where
     * a stale cached model would keep publishing a hidden or deleted media
     * URL until the stale window closes. The keys are derived from the
     * model's previous and current values, not a hand-maintained registry.
     */
    public function forgetPublicListings(Sermon $sermon): void
    {
        $previous = $sermon->getPrevious();

        $keys = collect([$sermon->service?->value, $previous['service'] ?? null])
            ->filter()
            ->map(fn (string $service): string => "sermons_service_{$service}")
            ->merge(
                collect([$sermon->series, $previous['series'] ?? null])
                    ->filter()
                    ->map(fn (string $series): string => 'sermons_series_'.Str::slug($series))
            );

        $preacherIds = collect([$sermon->preacher_id, $previous['preacher_id'] ?? null])
            ->filter()
            ->unique();

        if ($preacherIds->isNotEmpty()) {
            $keys = $keys->merge(
                Preacher::query()
                    ->whereIn('id', $preacherIds)
                    ->get(['id', 'slug', 'name'])
                    ->map(fn (Preacher $preacher): string => $this->preacherCacheKey($preacher))
            );
        }

        $keys->unique()->each(fn (string $key) => FlexibleCache::forget($key));
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
        return Cache::flexible('sermon_series', [300, 86400], function (): array {
            return $this->getExistingSeries();
        });
    }

    /**
     * Get distinct Bible books that have associated sermons, bypassing the cache.
     *
     * Scheduled sitemap generation reads this instead of getScriptureBooks():
     * a flexible-cache read in its stale window returns the old list and
     * defers the refresh until after the sitemap file has been written, so a
     * new archive URL would lag a full extra day.
     *
     * @return Collection<int, string>
     */
    public function getExistingScriptureBooks(): Collection
    {
        // Entries are only created for ContentType::Sermon, so no join is needed
        return SermonScriptureFilter::query()
            ->select('bible_book')
            ->distinct()
            ->pluck('bible_book');
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

        return Cache::flexible($cacheKey, [300, 86400], function () use ($preacherId, $series): Collection {
            $query = SermonScriptureFilter::query();

            if ($preacherId === null && $series === null) {
                return $this->getExistingScriptureBooks();
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

        return Cache::flexible($cacheKey, [300, 86400], function () use ($book, $preacherId, $series): Collection {
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
}
