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
    private const MEMO_NULL = '__memo_null__';

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
     * @var array<int|string, ?string>
     */
    private array $memoizedIso8601Durations = [];

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

    /**
     * @var array<string, string>
     */
    private array $memoizedMetaDescriptions = [];

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

        if (isset($this->memoizedDurations[$key])) {
            return $this->memoizedDurations[$key] === self::MEMO_NULL ? null : $this->memoizedDurations[$key];
        }

        if ($sermon->duration === null || $sermon->duration <= 0) {
            $this->memoizedDurations[$key] = self::MEMO_NULL;

            return null;
        }

        $seconds = (int) $sermon->duration;
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        return $this->memoizedDurations[$key] = $hours > 0
            ? "{$hours}h {$minutes}m"
            : "{$minutes}m";
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
        $this->memoizedIso8601Durations = [];
        $this->memoizedPresents = [];
        $this->memoizedTimestamps = [];
        $this->memoizedSermonKeys = [];
        $this->memoizedHumanDates = [];
        $this->memoizedSlugs = [];
        $this->memoizedMetaDescriptions = [];
    }

    public function audioUrl(Sermon $sermon): ?string
    {
        $key = $this->cacheKey($sermon, 'audio');

        if (isset($this->memoizedUrls[$key])) {
            return $this->memoizedUrls[$key] === self::MEMO_NULL ? null : $this->memoizedUrls[$key];
        }

        $url = filled($sermon->audio_file_path)
            ? $this->storageService->getAudioDeliveryUrl($sermon)
            : null;

        $this->memoizedUrls[$key] = $url ?? self::MEMO_NULL;

        return $url;
    }

    public function canonicalUrl(Sermon $sermon): string
    {
        return $this->memoizedUrls[$this->cacheKey($sermon, 'canonical')] ??= $this->exposurePolicy->canonicalUrl($sermon);
    }

    public function cardThumbnailUrl(Sermon $sermon): ?string
    {
        $key = $this->cacheKey($sermon, 'card_thumb');

        if (isset($this->memoizedUrls[$key])) {
            return $this->memoizedUrls[$key] === self::MEMO_NULL ? null : $this->memoizedUrls[$key];
        }

        $url = (function () use ($sermon) {
            if (! $this->exposurePolicy->shouldExposeThumbnail($sermon)) {
                return null;
            }

            if (! $sermon->hasPlainThumbnail()) {
                return null;
            }

            return $this->storageService->getCardThumbnailDeliveryUrl($sermon);
        })();

        $this->memoizedUrls[$key] = $url ?? self::MEMO_NULL;

        return $url;
    }

    public function plainThumbnailUrl(Sermon $sermon): ?string
    {
        $key = $this->cacheKey($sermon, 'plain_thumb');

        if (isset($this->memoizedUrls[$key])) {
            return $this->memoizedUrls[$key] === self::MEMO_NULL ? null : $this->memoizedUrls[$key];
        }

        $url = (function () use ($sermon) {
            if (! $this->exposurePolicy->shouldExposeThumbnail($sermon)) {
                return null;
            }

            if (! $sermon->hasPlainThumbnail()) {
                return null;
            }

            return $this->storageService->getPlainThumbnailDeliveryUrl($sermon);
        })();

        $this->memoizedUrls[$key] = $url ?? self::MEMO_NULL;

        return $url;
    }

    public function preacherUrl(Sermon $sermon): ?string
    {
        $preacherName = null;
        if ($sermon->preacher_id !== null) {
            $preacherKey = "id_{$sermon->preacher_id}";
        } else {
            $preacherName = $this->displayPreacherName($sermon);
            $preacherKey = (string) $preacherName;
        }

        if ($preacherKey === '') {
            return null;
        }

        if (isset($this->memoizedPreacherUrls[$preacherKey])) {
            return $this->memoizedPreacherUrls[$preacherKey] === self::MEMO_NULL ? null : $this->memoizedPreacherUrls[$preacherKey];
        }

        $url = (function () use ($sermon, $preacherName) {
            if ($sermon->relationLoaded('preacherProfile') && $sermon->preacherProfile !== null) {
                return route('sermons.preacher', ['preacher' => $sermon->preacherProfile->slug]);
            }

            $preacherName ??= $this->displayPreacherName($sermon);

            return filled($preacherName)
                ? route('sermons.preacher', ['preacher' => $this->slug($preacherName)])
                : null;
        })();

        $this->memoizedPreacherUrls[$preacherKey] = $url ?? self::MEMO_NULL;

        return $url;
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

        if (isset($this->memoizedSeriesUrls[$sermon->series])) {
            return $this->memoizedSeriesUrls[$sermon->series] === self::MEMO_NULL ? null : $this->memoizedSeriesUrls[$sermon->series];
        }

        $url = route('sermons.series.show', ['series' => $this->slug($sermon->series)]);

        $this->memoizedSeriesUrls[$sermon->series] = $url;

        return $url;
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

        if (isset($this->memoizedUrls[$key])) {
            return $this->memoizedUrls[$key] === self::MEMO_NULL ? null : $this->memoizedUrls[$key];
        }

        $url = (function () use ($sermon) {
            if (! $this->exposurePolicy->shouldExposeThumbnail($sermon)) {
                return null;
            }

            if (! $sermon->hasThumbnail()) {
                return null;
            }

            return $this->storageService->getThumbnailDeliveryUrl($sermon);
        })();

        $this->memoizedUrls[$key] = $url ?? self::MEMO_NULL;

        return $url;
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

        if (isset($this->memoizedPreacherNames[$identityKey])) {
            return $this->memoizedPreacherNames[$identityKey] === self::MEMO_NULL ? null : $this->memoizedPreacherNames[$identityKey];
        }

        $preacherName = ($sermon->relationLoaded('preacherProfile') && $sermon->preacherProfile !== null)
            ? $sermon->preacherProfile->name
            : trim((string) $sermon->preacher);

        if ($preacherName === '') {
            $this->memoizedPreacherNames[$identityKey] = self::MEMO_NULL;

            return null;
        }

        return $this->memoizedPreacherNames[$identityKey] = $preacherName;
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

        if (isset($this->memoizedReferences[$identityKey])) {
            return $this->memoizedReferences[$identityKey] === self::MEMO_NULL ? null : $this->memoizedReferences[$identityKey];
        }

        if ($sermon->relationLoaded('scripturePassage') && $sermon->scripturePassage instanceof ScripturePassage) {
            $displayReference = $sermon->scripturePassage->display_reference ?: $sermon->scripturePassage->normalized_reference;

            if (trim((string) $displayReference) !== '') {
                return $this->memoizedReferences[$identityKey] = $displayReference;
            }
        }

        $reference = trim((string) $sermon->reference);

        if ($reference === '') {
            $this->memoizedReferences[$identityKey] = self::MEMO_NULL;

            return null;
        }

        return $this->memoizedReferences[$identityKey] = $reference;
    }

    /**
     * Get the duration of the sermon in ISO 8601 format (e.g. PT45M12S).
     *
     * Performance Optimization: Memoizes ISO 8601 duration strings
     * to avoid redundant Carbon object creation in listing contexts.
     */
    public function durationIso8601(Sermon $sermon): ?string
    {
        $key = $this->cacheKey($sermon, 'iso_dur');

        if (isset($this->memoizedIso8601Durations[$key])) {
            return $this->memoizedIso8601Durations[$key] === self::MEMO_NULL ? null : $this->memoizedIso8601Durations[$key];
        }

        if ($sermon->duration === null || $sermon->duration <= 0) {
            $this->memoizedIso8601Durations[$key] = self::MEMO_NULL;

            return null;
        }

        $duration = \Carbon\CarbonInterval::seconds($sermon->duration)->cascade()->spec();

        return $this->memoizedIso8601Durations[$key] = $duration;
    }

    /**
     * Generate the SEO meta description for a sermon.
     *
     * Performance Optimization: Memoizes generated meta descriptions to avoid
     * redundant assembly logic for the same sermon within a single request.
     * Checks for existing database-stored descriptions before falling back
     * to dynamic generation.
     */
    public function metaDescription(Sermon $sermon): string
    {
        $key = $this->cacheKey($sermon, 'meta_desc');

        if (isset($this->memoizedMetaDescriptions[$key])) {
            return $this->memoizedMetaDescriptions[$key];
        }

        $attributes = $sermon->getAttributes();
        if (filled($attributes['meta_description'] ?? null)) {
            return $this->memoizedMetaDescriptions[$key] = (string) $attributes['meta_description'];
        }

        $preacherName = $this->displayPreacherName($sermon) ?? 'Unknown preacher';
        $base = "Listen to '{$sermon->title}' by {$preacherName} preached on {$sermon->human_date}";

        if ($reference = $this->displayReference($sermon)) {
            $base .= " - {$reference}";
        }

        if ($sermon->series) {
            $base .= " (Part of the {$sermon->series} series)";
        }

        if (! $sermon->show_summary || ! $sermon->summary) {
            return Str::limit($base, 155);
        }

        $summary = trim(strip_tags((string) $sermon->summary));
        if ($summary === '') {
            return Str::limit($base, 155);
        }

        $full = "{$base}. {$summary}";
        if (Str::length($full) <= 155) {
            return $full;
        }

        $remaining = 155 - Str::length($base) - 2; // 2 for ". "

        if ($remaining > 0) {
            return $this->memoizedMetaDescriptions[$key] = $base.'. '.Str::limit($summary, $remaining);
        }

        return $this->memoizedMetaDescriptions[$key] = Str::limit($base, 155);
    }

    public function videoUrl(Sermon $sermon): ?string
    {
        $key = $this->cacheKey($sermon, 'video');

        if (isset($this->memoizedUrls[$key])) {
            return $this->memoizedUrls[$key] === self::MEMO_NULL ? null : $this->memoizedUrls[$key];
        }

        $url = (function () use ($sermon) {
            if (! $this->exposurePolicy->shouldExposeVideo($sermon)) {
                return null;
            }

            return $this->storageService->getVideoDeliveryUrl($sermon);
        })();

        $this->memoizedUrls[$key] = $url ?? self::MEMO_NULL;

        return $url;
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
     * Uses isset() for faster lookup performance and handles unpersisted models
     * by falling back to object hashes.
     */
    private function cacheKey(Sermon $sermon, string $type): string
    {
        $id = $sermon->id ?? 'u'.spl_object_id($sermon);

        if (! isset($this->memoizedSermonKeys[$id])) {
            if (! isset($this->memoizedTimestamps[$id])) {
                $this->memoizedTimestamps[$id] = $sermon->updated_at?->getTimestamp() ?? 0;
            }

            $this->memoizedSermonKeys[$id] = "{$id}_{$this->memoizedTimestamps[$id]}";
        }

        return "{$type}_{$this->memoizedSermonKeys[$id]}";
    }
}
