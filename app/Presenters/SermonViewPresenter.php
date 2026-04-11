<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Models\Sermon;
use App\Services\SermonExposurePolicy;
use App\Services\SermonStorageService;
use App\Services\SermonTranscriptReader;
use Illuminate\Support\Str;

class SermonViewPresenter
{
    /**
     * @var array<string, string|null>
     */
    private array $memoizedUrls = [];

    /**
     * @var array<string, string|null>
     */
    private array $memoizedGlobalUrls = [];

    public function __construct(
        private readonly SermonExposurePolicy $exposurePolicy,
        private readonly SermonStorageService $storageService,
        private readonly SermonTranscriptReader $transcriptReader,
    ) {}

    /**
     * Clear the internal URL cache.
     * Useful for long-running processes or tests.
     */
    public function clearInternalCaches(): void
    {
        $this->memoizedUrls = [];
        $this->memoizedGlobalUrls = [];
    }

    public function audioUrl(Sermon $sermon): ?string
    {
        $key = $this->cacheKey($sermon, 'audio');

        if (! array_key_exists($key, $this->memoizedUrls)) {
            $this->memoizedUrls[$key] = filled($sermon->audio_file_path)
                ? $this->storageService->getPublicUrl($sermon)
                : null;
        }

        return $this->memoizedUrls[$key];
    }

    public function canonicalUrl(Sermon $sermon): string
    {
        return $this->memoizedUrls[$this->cacheKey($sermon, 'canonical')] ??= $this->exposurePolicy->canonicalUrl($sermon);
    }

    public function cardThumbnailUrl(Sermon $sermon): ?string
    {
        $key = $this->cacheKey($sermon, 'card_thumb');

        if (! array_key_exists($key, $this->memoizedUrls)) {
            $this->memoizedUrls[$key] = (function () use ($sermon) {
                if (! $this->exposurePolicy->shouldExposeThumbnail($sermon)) {
                    return null;
                }

                if (! $sermon->hasPlainThumbnail()) {
                    return null;
                }

                return $this->storageService->getCardThumbnailUrl($sermon);
            })();
        }

        return $this->memoizedUrls[$key];
    }

    public function plainThumbnailUrl(Sermon $sermon): ?string
    {
        $key = $this->cacheKey($sermon, 'plain_thumb');

        if (! array_key_exists($key, $this->memoizedUrls)) {
            $this->memoizedUrls[$key] = (function () use ($sermon) {
                if (! $this->exposurePolicy->shouldExposeThumbnail($sermon)) {
                    return null;
                }

                if (! $sermon->hasPlainThumbnail()) {
                    return null;
                }

                return $this->storageService->getPlainThumbnailUrl($sermon);
            })();
        }

        return $this->memoizedUrls[$key];
    }

    /**
     * Get the preacher profile URL for a sermon.
     *
     * Performance Optimization: Memoizes results based on preacher ID or slug
     * across all sermons in the request to eliminate redundant calculations
     * and relationship checks in listing pages.
     */
    public function preacherUrl(Sermon $sermon): ?string
    {
        if ($sermon->relationLoaded('preacherProfile') && $sermon->preacherProfile !== null) {
            $key = "preacher_{$sermon->preacherProfile->id}";

            return $this->memoizedGlobalUrls[$key] ??= "/christ/sermons/preachers/{$sermon->preacherProfile->slug}";
        }

        $preacherName = $sermon->displayPreacherName();

        if (! filled($preacherName)) {
            return null;
        }

        $slug = Str::slug($preacherName);
        $key = "preacher_slug_{$slug}";

        return $this->memoizedGlobalUrls[$key] ??= "/christ/sermons/preachers/{$slug}";
    }

    /**
     * Get the sermon series URL.
     *
     * Performance Optimization: Memoizes results based on series slug across
     * all sermons in the request to eliminate redundant slug calculations.
     */
    public function seriesUrl(Sermon $sermon): ?string
    {
        if (! filled($sermon->series)) {
            return null;
        }

        $slug = Str::slug($sermon->series);
        $key = "series_{$slug}";

        return $this->memoizedGlobalUrls[$key] ??= "/christ/sermons/series/{$slug}";
    }

    /**
     * Present a lightweight subset of sermon view data for use in API resources.
     *
     * Performance Optimization: Only returns fields required by the public API,
     * avoiding expensive operations like reading full transcripts from cache or storage
     * and generating non-API URLs (like canonical URLs).
     *
     * @return array{
     *     audio_url: ?string,
     *     preacher_url: ?string,
     *     series_url: ?string,
     *     thumbnail_url: ?string,
     *     video_url: ?string
     * }
     */
    public function presentForApi(Sermon $sermon): array
    {
        return [
            'audio_url' => $this->audioUrl($sermon),
            'preacher_url' => $this->preacherUrl($sermon),
            'series_url' => $this->seriesUrl($sermon),
            'thumbnail_url' => $this->thumbnailUrl($sermon),
            'video_url' => $this->videoUrl($sermon),
        ];
    }

    /**
     * @return array{
     *     audio_url: ?string,
     *     canonical_url: string,
     *     card_thumbnail_url: ?string,
     *     preacher_url: ?string,
     *     public_url: string,
     *     series_url: ?string,
     *     thumbnail_url: ?string,
     *     transcript: ?string,
     *     video_url: ?string
     * }
     */
    public function present(Sermon $sermon): array
    {
        return [
            'audio_url' => $this->audioUrl($sermon),
            'canonical_url' => $this->canonicalUrl($sermon),
            'card_thumbnail_url' => $this->cardThumbnailUrl($sermon),
            'preacher_url' => $this->preacherUrl($sermon),
            'public_url' => $this->publicUrl($sermon),
            'series_url' => $this->seriesUrl($sermon),
            'thumbnail_url' => $this->thumbnailUrl($sermon),
            'transcript' => $this->transcriptReader->read($sermon),
            'video_url' => $this->videoUrl($sermon),
        ];
    }

    public function publicUrl(Sermon $sermon): string
    {
        return $this->memoizedUrls[$this->cacheKey($sermon, 'public')] ??= $this->exposurePolicy->publicUrl($sermon);
    }

    public function thumbnailUrl(Sermon $sermon): ?string
    {
        $key = $this->cacheKey($sermon, 'thumb');

        if (! array_key_exists($key, $this->memoizedUrls)) {
            $this->memoizedUrls[$key] = (function () use ($sermon) {
                if (! $this->exposurePolicy->shouldExposeThumbnail($sermon)) {
                    return null;
                }

                if (! $sermon->hasThumbnail()) {
                    return null;
                }

                return $this->storageService->getThumbnailUrl($sermon);
            })();
        }

        return $this->memoizedUrls[$key];
    }

    public function transcript(Sermon $sermon): ?string
    {
        return $this->transcriptReader->read($sermon);
    }

    public function videoUrl(Sermon $sermon): ?string
    {
        $key = $this->cacheKey($sermon, 'video');

        if (! array_key_exists($key, $this->memoizedUrls)) {
            $this->memoizedUrls[$key] = (function () use ($sermon) {
                if (! $this->exposurePolicy->shouldExposeVideo($sermon)) {
                    return null;
                }

                return $this->storageService->getVideoUrl($sermon);
            })();
        }

        return $this->memoizedUrls[$key];
    }

    /**
     * Generate a cache key for the given sermon and type.
     *
     * Performance Optimization: Uses a raw timestamp to avoid redundant
     * Carbon object instantiation or property access.
     */
    private function cacheKey(Sermon $sermon, string $type): string
    {
        $timestamp = $sermon->updated_at->timestamp ?? 0;

        return "{$type}_{$sermon->id}_{$timestamp}";
    }
}
