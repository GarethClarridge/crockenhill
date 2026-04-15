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

    /**
     * @var array<int|string, ?string>
     */
    private array $memoizedPreacherNames = [];

    /**
     * @var array<int|string, ?string>
     */
    private array $memoizedReferences = [];

    /**
     * @var array<int|string, ?string>
     */
    private array $memoizedDurations = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $memoizedPresents = [];

    /**
     * @var array<int|string, int>
     */
    private array $memoizedTimestamps = [];

    /**
     * @var array<int|string, string>
     */
    private array $memoizedSermonKeys = [];

    /**
     * @var array<int, string>
     */
    private array $memoizedHumanDates = [];

    /**
     * @var array<string, string>
     */
    private array $memoizedSlugs = [];

    public function __construct(
        private readonly SermonExposurePolicy $exposurePolicy,
        private readonly SermonStorageService $storageService,
        private readonly SermonTranscriptReader $transcriptReader,
    ) {}

    /**
     * Get the human-friendly formatted duration of the sermon.
     *
     * Performance Optimization: Memoizes duration formatting results
     * to avoid redundant calculations across multiple views and components.
     */
    public function formattedDuration(Sermon $sermon): ?string
    {
        $key = $this->cacheKey($sermon, 'duration');

        if (array_key_exists($key, $this->memoizedDurations)) {
            return $this->memoizedDurations[$key];
        }

        if ($sermon->duration === null || $sermon->duration <= 0) {
            return $this->memoizedDurations[$key] = null;
        }

        $seconds = (int) $sermon->duration;
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        if ($hours > 0) {
            return $this->memoizedDurations[$key] = "{$hours}h {$minutes}m";
        }

        return $this->memoizedDurations[$key] = "{$minutes}m";
    }

    /**
     * Get the human-friendly date of the sermon.
     *
     * Performance Optimization: Memoizes date formatting results by timestamp
     * to avoid redundant object calls and string formatting across multiple
     * sermons sharing the same date in a listing.
     */
    public function humanDate(Sermon $sermon): string
    {
        $timestamp = $sermon->date->getTimestamp();

        return $this->memoizedHumanDates[$timestamp] ??= $sermon->date->format('F j, Y');
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
        $this->memoizedPreacherNames = [];
        $this->memoizedReferences = [];
        $this->memoizedDurations = [];
        $this->memoizedPresents = [];
        $this->memoizedTimestamps = [];
        $this->memoizedSermonKeys = [];
        $this->memoizedHumanDates = [];
        $this->memoizedSlugs = [];
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
        $preacherKey = $sermon->preacher_id !== null
            ? "id_{$sermon->preacher_id}"
            : (string) $this->displayPreacherName($sermon);

        if ($preacherKey === '') {
            return null;
        }

        if (! array_key_exists($preacherKey, $this->memoizedPreacherUrls)) {
            $this->memoizedPreacherUrls[$preacherKey] = (function () use ($sermon) {
                if ($sermon->relationLoaded('preacherProfile') && $sermon->preacherProfile !== null) {
                    return route('sermons.preacher', ['preacher' => $sermon->preacherProfile->slug]);
                }

                $preacherName = $this->displayPreacherName($sermon);

                return filled($preacherName)
                    ? route('sermons.preacher', ['preacher' => $this->slug($preacherName)])
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

        return $this->memoizedSeriesUrls[$sermon->series] ??= route('sermons.series.show', ['series' => $this->slug($sermon->series)]);
    }

    /**
     * Present a lightweight subset of sermon view data for use in API resources.
     *
     * Performance Optimization: Consolidates all required display fields into a
     * single array to eliminate redundant presenter method calls and container
     * lookups during API resource transformation.
     *
     * @return array{
     *     audio_url: ?string,
     *     display_reference: ?string,
     *     formatted_duration: ?string,
     *     human_date: string,
     *     preacher_name: ?string,
     *     preacher_url: ?string,
     *     series_url: ?string,
     *     thumbnail_url: ?string,
     *     video_url: ?string
     * }
     */
    public function presentForApi(Sermon $sermon): array
    {
        $key = $this->cacheKey($sermon, 'api_present');

        /** @var array{audio_url: ?string, display_reference: ?string, formatted_duration: ?string, human_date: string, preacher_name: ?string, preacher_url: ?string, series_url: ?string, thumbnail_url: ?string, video_url: ?string} */
        return $this->memoizedPresents[$key] ??= [
            'audio_url' => $this->audioUrl($sermon),
            'display_reference' => $this->displayReference($sermon),
            'formatted_duration' => $this->formattedDuration($sermon),
            'human_date' => $this->humanDate($sermon),
            'preacher_name' => $this->displayPreacherName($sermon),
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
     *     display_reference: ?string,
     *     formatted_duration: ?string,
     *     human_date: string,
     *     preacher_name: ?string,
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
        $key = $this->cacheKey($sermon, 'full_present');

        /** @var array{audio_url: ?string, canonical_url: string, card_thumbnail_url: ?string, display_reference: ?string, formatted_duration: ?string, human_date: string, preacher_name: ?string, preacher_url: ?string, public_url: string, series_url: ?string, thumbnail_url: ?string, transcript: ?string, video_url: ?string} */
        return $this->memoizedPresents[$key] ??= [
            'audio_url' => $this->audioUrl($sermon),
            'canonical_url' => $this->canonicalUrl($sermon),
            'card_thumbnail_url' => $this->cardThumbnailUrl($sermon),
            'display_reference' => $this->displayReference($sermon),
            'formatted_duration' => $this->formattedDuration($sermon),
            'human_date' => $this->humanDate($sermon),
            'preacher_name' => $this->displayPreacherName($sermon),
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

    /**
     * Get the preacher name for display.
     *
     * Performance Optimization: Memoizes preacher name lookup by identity
     * (profile ID or name string) instead of sermon ID. This avoids redundant
     * lookups across multiple sermons in a listing by the same preacher.
     */
    public function displayPreacherName(Sermon $sermon): ?string
    {
        $identityKey = $sermon->preacher_id !== null
            ? "id_{$sermon->preacher_id}"
            : (string) $sermon->preacher;

        if ($identityKey === '') {
            return null;
        }

        if (array_key_exists($identityKey, $this->memoizedPreacherNames)) {
            return $this->memoizedPreacherNames[$identityKey];
        }

        $preacherName = ($sermon->relationLoaded('preacherProfile') && $sermon->preacherProfile !== null)
            ? $sermon->preacherProfile->name
            : trim((string) $sermon->preacher);

        return $this->memoizedPreacherNames[$identityKey] = ($preacherName !== '' ? $preacherName : null);
    }

    /**
     * Get the scripture reference for display.
     *
     * Performance Optimization: Memoizes reference lookup by identity
     * (passage ID or reference string) instead of sermon ID. This avoids
     * redundant lookups across multiple sermons in a listing using the
     * same bible passage.
     */
    public function displayReference(Sermon $sermon): ?string
    {
        $identityKey = $sermon->scripture_passage_id !== null
            ? "id_{$sermon->scripture_passage_id}"
            : (string) $sermon->reference;

        if ($identityKey === '') {
            return null;
        }

        if (array_key_exists($identityKey, $this->memoizedReferences)) {
            return $this->memoizedReferences[$identityKey];
        }

        if ($sermon->relationLoaded('scripturePassage') && $sermon->scripturePassage instanceof ScripturePassage) {
            $displayReference = $sermon->scripturePassage->display_reference ?: $sermon->scripturePassage->normalized_reference;

            if (trim((string) $displayReference) !== '') {
                return $this->memoizedReferences[$identityKey] = $displayReference;
            }
        }

        $reference = trim((string) $sermon->reference);

        return $this->memoizedReferences[$identityKey] = ($reference !== '' ? $reference : null);
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
     * Internal helper to generate slugs with request-level memoization.
     *
     * Performance Optimization: Avoids redundant Str::slug() calls which
     * can be expensive when processing hundreds of strings in a listing.
     */
    private function slug(string $value): string
    {
        return $this->memoizedSlugs[$value] ??= Str::slug($value);
    }

    /**
     * Generate a cache key for the given sermon and type.
     *
     * Performance Optimization: Memoizes the sermon's combined ID and timestamp
     * within the request to avoid redundant string concatenations and Carbon
     * object method calls when generating keys for multiple sermon attributes.
     */
    private function cacheKey(Sermon $sermon, string $type): string
    {
        if (! array_key_exists($sermon->id, $this->memoizedSermonKeys)) {
            if (! array_key_exists($sermon->id, $this->memoizedTimestamps)) {
                $this->memoizedTimestamps[$sermon->id] = $sermon->updated_at?->getTimestamp() ?? 0;
            }

            $this->memoizedSermonKeys[$sermon->id] = "{$sermon->id}_{$this->memoizedTimestamps[$sermon->id]}";
        }

        return "{$type}_{$this->memoizedSermonKeys[$sermon->id]}";
    }
}
