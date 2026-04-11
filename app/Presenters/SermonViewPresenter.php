<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Models\ScripturePassage;
use App\Models\Sermon;
use App\Services\SermonExposurePolicy;
use App\Services\SermonStorageService;
use App\Services\SermonTranscriptReader;
use Illuminate\Support\Str;

class SermonViewPresenter
{
    /**
     * @var array<string, mixed>
     */
    private array $memoizedUrls = [];

    /**
     * @var array<string, ?string>
     */
    private array $memoizedPreacherUrls = [];

    /**
     * @var array<string, ?string>
     */
    private array $memoizedSeriesUrls = [];

    public function __construct(
        private readonly SermonExposurePolicy $exposurePolicy,
        private readonly SermonStorageService $storageService,
        private readonly SermonTranscriptReader $transcriptReader,
    ) {}

    /**
     * Get the human-friendly formatted duration of the sermon.
     */
    public function formattedDuration(Sermon $sermon): ?string
    {
        if ($sermon->duration === null || $sermon->duration <= 0) {
            return null;
        }

        $seconds = (int) $sermon->duration;
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        if ($hours > 0) {
            return "{$hours}h {$minutes}m";
        }

        return "{$minutes}m";
    }

    /**
     * Clear the internal URL cache.
     * Useful for long-running processes or tests.
     */
    public function clearInternalCaches(): void
    {
        $this->memoizedUrls = [];
        $this->memoizedPreacherUrls = [];
        $this->memoizedSeriesUrls = [];
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

    public function preacherUrl(Sermon $sermon): ?string
    {
        $preacherKey = (string) ($sermon->preacher_id ?? Str::slug($this->displayPreacherName($sermon) ?? ''));

        if ($preacherKey === '') {
            return null;
        }

        if (! array_key_exists($preacherKey, $this->memoizedPreacherUrls)) {
            $this->memoizedPreacherUrls[$preacherKey] = (function () use ($sermon) {
                if ($sermon->relationLoaded('preacherProfile') && $sermon->preacherProfile !== null) {
                    return '/christ/sermons/preachers/'.$sermon->preacherProfile->slug;
                }

                $preacherName = $this->displayPreacherName($sermon);

                return filled($preacherName)
                    ? '/christ/sermons/preachers/'.Str::slug($preacherName)
                    : null;
            })();
        }

        return $this->memoizedPreacherUrls[$preacherKey];
    }

    /**
     * Get the URL for a sermon series.
     *
     * Performance Optimization: Memoizes series URLs based on the series name
     * to avoid redundant Str::slug calls when processing large sermon collections.
     */
    public function seriesUrl(Sermon $sermon): ?string
    {
        if (! $sermon->series) {
            return null;
        }

        return $this->memoizedSeriesUrls[$sermon->series] ??= '/christ/sermons/series/'.Str::slug($sermon->series);
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
     *     formatted_duration: ?string,
     *     preacher_url: ?string,
     *     thumbnail_url: ?string,
     *     video_url: ?string
     * }
     */
    public function presentForApi(Sermon $sermon): array
    {
        return [
            'audio_url' => $this->audioUrl($sermon),
            'formatted_duration' => $this->formattedDuration($sermon),
            'preacher_url' => $this->preacherUrl($sermon),
            'thumbnail_url' => $this->thumbnailUrl($sermon),
            'video_url' => $this->videoUrl($sermon),
        ];
    }

    /**
     * @return array{
     *     audio_url: ?string,
     *     canonical_url: string,
     *     card_thumbnail_url: ?string,
     *     formatted_duration: ?string,
     *     preacher_url: ?string,
     *     public_url: string,
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
            'formatted_duration' => $this->formattedDuration($sermon),
            'preacher_url' => $this->preacherUrl($sermon),
            'public_url' => $this->publicUrl($sermon),
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

    public function displayPreacherName(Sermon $sermon): ?string
    {
        $preacherName = $sermon->relationLoaded('preacherProfile')
            ? $sermon->preacherProfile?->name
            : null;

        $preacherName = trim((string) ($preacherName ?? $sermon->preacher));

        return $preacherName !== '' ? $preacherName : null;
    }

    public function displayReference(Sermon $sermon): ?string
    {
        if ($sermon->relationLoaded('scripturePassage') && $sermon->scripturePassage instanceof ScripturePassage) {
            $displayReference = $sermon->scripturePassage->display_reference ?: $sermon->scripturePassage->normalized_reference;

            if (trim((string) $displayReference) !== '') {
                return $displayReference;
            }
        }

        $reference = trim((string) $sermon->reference);

        return $reference !== '' ? $reference : null;
    }

    public function metaDescription(Sermon $sermon): string
    {
        if (! empty($sermon->getAttributes()['meta_description'] ?? null)) {
            return $sermon->getAttributes()['meta_description'];
        }

        $preacherName = $this->displayPreacherName($sermon) ?? 'Unknown preacher';
        $description = "Listen to '{$sermon->title}' by {$preacherName}";
        $description .= " preached on {$sermon->human_date}";

        if ($this->displayReference($sermon)) {
            $description .= ' - '.$this->displayReference($sermon);
        }

        $summary = null;
        if ($sermon->show_summary && $sermon->summary) {
            $summary = trim(strip_tags($sermon->summary));
        }

        $seriesSuffix = $sermon->series ? " (Part of the {$sermon->series} series)" : '';

        if ($summary === null || $summary === '') {
            return Str::limit($description.$seriesSuffix, 155);
        }

        $descriptionWithSeries = $description.$seriesSuffix;
        $separator = '. ';

        if (Str::length($descriptionWithSeries.$separator.$summary) <= 155) {
            return $descriptionWithSeries.$separator.$summary;
        }

        $remainingSummaryLength = 155 - Str::length($description) - Str::length($separator);

        if ($remainingSummaryLength > 0) {
            return $description.$separator.Str::limit($summary, $remainingSummaryLength);
        }

        return Str::limit($descriptionWithSeries, 155);
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
     */
    private function cacheKey(Sermon $sermon, string $type): string
    {
        $timestamp = $sermon->updated_at?->getTimestamp() ?? 0;

        return "{$type}_{$sermon->id}_{$timestamp}";
    }
}
