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
    private array $memoizedSeriesUrls = [];

    /**
     * @var array<int|string, ?string>
     */
    private array $memoizedPreacherNames = [];

    /**
     * @var array<int|string, ?string>
     */
    private array $memoizedPreacherUrls = [];

    /**
     * @var array<int|string, ?string>
     */
    private array $memoizedPreacherImageUrls = [];

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
    private array $memoizedIsoDurations = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $memoizedPresents = [];

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
            $value = $this->memoizedDurations[$key];

            return $value === self::MEMO_NULL ? null : (string) $value;
        }

        if ($sermon->duration === null || $sermon->duration <= 0) {
            $this->memoizedDurations[$key] = self::MEMO_NULL;

            return null;
        }

        $seconds = (int) $sermon->duration;
        $hours = (int) floor($seconds / 3600);
        $minutes = (int) floor(($seconds % 3600) / 60);

        $result = $hours > 0
            ? "{$hours}h {$minutes}m"
            : "{$minutes}m";

        return $this->memoizedDurations[$key] = $result;
    }

    /**
     * Get the ISO 8601 duration string (e.g. PT45M) for the sermon.
     *
     * Performance Optimization: Memoizes duration formatting results
     * to avoid redundant calculations across multiple views and components.
     */
    public function durationIso8601(Sermon $sermon): ?string
    {
        $key = $this->cacheKey($sermon, 'duration_iso');

        if (isset($this->memoizedIsoDurations[$key])) {
            $value = $this->memoizedIsoDurations[$key];

            return $value === self::MEMO_NULL ? null : (string) $value;
        }

        if ($sermon->duration === null || $sermon->duration <= 0) {
            $this->memoizedIsoDurations[$key] = self::MEMO_NULL;

            return null;
        }

        return $this->memoizedIsoDurations[$key] = \Carbon\CarbonInterval::seconds($sermon->duration)->cascade()->spec();
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
        $this->memoizedSeriesUrls = [];
        $this->memoizedPreacherNames = [];
        $this->memoizedPreacherUrls = [];
        $this->memoizedPreacherImageUrls = [];
        $this->memoizedReferences = [];
        $this->memoizedDurations = [];
        $this->memoizedPresents = [];
        $this->memoizedSermonKeys = [];
        $this->memoizedHumanDates = [];
        $this->memoizedSlugs = [];
        $this->memoizedMetaDescriptions = [];
        $this->memoizedIsoDurations = [];
    }

    public function audioUrl(Sermon $sermon): ?string
    {
        $key = $this->cacheKey($sermon, 'audio');

        if (isset($this->memoizedUrls[$key])) {
            $value = $this->memoizedUrls[$key];

            return $value === self::MEMO_NULL ? null : (string) $value;
        }

        $url = filled($sermon->audio_file_path)
            ? $this->storageService->getAudioDeliveryUrl($sermon)
            : null;

        $this->memoizedUrls[$key] = $url ?? self::MEMO_NULL;

        return $url;
    }

    /**
     * Get the preacher's profile image URL.
     *
     * Performance Optimization: Memoizes preacher image URL lookup by identity
     * (profile ID or name string) to avoid redundant attribute resolutions
     * across multiple sermons in a listing by the same preacher.
     *
     * Relation Loading: Always consults the loaded relation first to ensure
     * the most current state is returned.
     */
    public function preacherImageUrl(Sermon $sermon): ?string
    {
        $identityKey = $sermon->preacher_id !== null
            ? "id_{$sermon->preacher_id}"
            : (string) $sermon->preacher;

        if ($identityKey === '') {
            return null;
        }

        if (isset($this->memoizedPreacherImageUrls[$identityKey])) {
            $value = $this->memoizedPreacherImageUrls[$identityKey];

            return $value === self::MEMO_NULL ? null : (string) $value;
        }

        // If the relation is explicitly loaded, always use it as the source of truth
        if ($sermon->relationLoaded('preacherProfile')) {
            return $this->memoizedPreacherImageUrls[$identityKey] = $sermon->preacherProfile->profile_image_url ?? self::MEMO_NULL;
        }

        // Without a loaded relation, we cannot determine the image URL
        return null;
    }

    public function canonicalUrl(Sermon $sermon): string
    {
        $value = $this->memoizedUrls[$this->cacheKey($sermon, 'canonical')] ??= $this->exposurePolicy->canonicalUrl($sermon);

        return (string) $value;
    }

    public function cardThumbnailUrl(Sermon $sermon): ?string
    {
        $key = $this->cacheKey($sermon, 'card_thumb');

        if (isset($this->memoizedUrls[$key])) {
            $value = $this->memoizedUrls[$key];

            return $value === self::MEMO_NULL ? null : (string) $value;
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
            $value = $this->memoizedUrls[$key];

            return $value === self::MEMO_NULL ? null : (string) $value;
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
        $identityKey = $sermon->preacher_id !== null
            ? "id_{$sermon->preacher_id}"
            : (string) $sermon->preacher;

        if ($identityKey === '') {
            return null;
        }

        if (isset($this->memoizedPreacherUrls[$identityKey])) {
            $value = $this->memoizedPreacherUrls[$identityKey];

            return $value === self::MEMO_NULL ? null : (string) $value;
        }

        // If the relation is explicitly loaded, always use it as the source of truth
        if ($sermon->relationLoaded('preacherProfile') && $sermon->preacherProfile !== null) {
            return $this->memoizedPreacherUrls[$identityKey] = route('sermons.preacher', ['preacher' => $sermon->preacherProfile->slug]);
        }

        // Fall back to the unloaded path: derive URL from displayPreacherName
        $preacherName = $this->displayPreacherName($sermon);

        return $this->memoizedPreacherUrls[$identityKey] = filled($preacherName)
            ? route('sermons.preacher', ['preacher' => $this->slug((string) $preacherName)])
            : self::MEMO_NULL;
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
            $value = $this->memoizedSeriesUrls[$sermon->series];

            return $value === self::MEMO_NULL ? null : (string) $value;
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
     *     duration_iso8601: ?string,
     *     formatted_duration: ?string,
     *     human_date: string,
     *     preacher_image_url: ?string,
     *     preacher_name: ?string,
     *     preacher_url: ?string,
     *     series_url: ?string,
     *     thumbnail_url: ?string,
     *     plain_thumbnail_url: ?string,
     *     video_url: ?string
     * }
     */
    public function presentForApi(Sermon $sermon): array
    {
        $key = $this->cacheKey($sermon, 'api_present');

        /** @var array{audio_url: ?string, display_reference: ?string, duration_iso8601: ?string, formatted_duration: ?string, human_date: string, preacher_image_url: ?string, preacher_name: ?string, preacher_url: ?string, series_url: ?string, thumbnail_url: ?string, plain_thumbnail_url: ?string, video_url: ?string} */
        return $this->memoizedPresents[$key] ??= [
            'audio_url' => $this->audioUrl($sermon),
            'display_reference' => $this->displayReference($sermon),
            'duration_iso8601' => $this->durationIso8601($sermon),
            'formatted_duration' => $this->formattedDuration($sermon),
            'human_date' => $this->humanDate($sermon),
            'preacher_image_url' => $this->preacherImageUrl($sermon),
            'preacher_name' => $this->displayPreacherName($sermon),
            'preacher_url' => $this->preacherUrl($sermon),
            'series_url' => $this->seriesUrl($sermon),
            'thumbnail_url' => $this->thumbnailUrl($sermon),
            'plain_thumbnail_url' => $this->plainThumbnailUrl($sermon),
            'video_url' => $this->videoUrl($sermon),
        ];
    }

    /**
     * Present a lightweight subset of sermon view data for use in listings and JSON-LD.
     *
     * Performance Optimization: Omits the expensive transcript content fetching
     * which is unnecessary for collection views but includes all URLs and metadata.
     *
     * @return array{
     *     audio_url: ?string,
     *     canonical_url: string,
     *     card_thumbnail_url: ?string,
     *     display_reference: ?string,
     *     duration_iso8601: ?string,
     *     formatted_duration: ?string,
     *     has_transcript: bool,
     *     human_date: string,
     *     preacher_image_url: ?string,
     *     preacher_name: ?string,
     *     preacher_url: ?string,
     *     public_url: string,
     *     series_url: ?string,
     *     thumbnail_url: ?string,
     *     plain_thumbnail_url: ?string,
     *     transcript_url: ?string,
     *     video_url: ?string
     * }
     */
    public function presentForList(Sermon $sermon): array
    {
        $key = $this->cacheKey($sermon, 'list_present');

        /** @var array{audio_url: ?string, canonical_url: string, card_thumbnail_url: ?string, display_reference: ?string, duration_iso8601: ?string, formatted_duration: ?string, has_transcript: bool, human_date: string, preacher_image_url: ?string, preacher_name: ?string, preacher_url: ?string, public_url: string, series_url: ?string, thumbnail_url: ?string, plain_thumbnail_url: ?string, transcript_url: ?string, video_url: ?string} */
        return $this->memoizedPresents[$key] ??= [
            'audio_url' => $this->audioUrl($sermon),
            'canonical_url' => $this->canonicalUrl($sermon),
            'card_thumbnail_url' => $this->cardThumbnailUrl($sermon),
            'display_reference' => $this->displayReference($sermon),
            'duration_iso8601' => $this->durationIso8601($sermon),
            'formatted_duration' => $this->formattedDuration($sermon),
            'has_transcript' => $sermon->hasTranscript(),
            'human_date' => $this->humanDate($sermon),
            'preacher_image_url' => $this->preacherImageUrl($sermon),
            'preacher_name' => $this->displayPreacherName($sermon),
            'preacher_url' => $this->preacherUrl($sermon),
            'public_url' => $this->publicUrl($sermon),
            'series_url' => $this->seriesUrl($sermon),
            'thumbnail_url' => $this->thumbnailUrl($sermon),
            'plain_thumbnail_url' => $this->plainThumbnailUrl($sermon),
            'transcript_url' => $sermon->hasTranscript() ? route('sermons.transcript', ['sermon' => $sermon->slug]) : null,
            'video_url' => $this->videoUrl($sermon),
        ];
    }

    /**
     * @return array{
     *     audio_url: ?string,
     *     canonical_url: string,
     *     card_thumbnail_url: ?string,
     *     display_reference: ?string,
     *     duration_iso8601: ?string,
     *     formatted_duration: ?string,
     *     has_transcript: bool,
     *     human_date: string,
     *     preacher_image_url: ?string,
     *     preacher_name: ?string,
     *     preacher_url: ?string,
     *     public_url: string,
     *     series_url: ?string,
     *     thumbnail_url: ?string,
     *     transcript: ?string,
     *     transcript_url: ?string,
     *     video_url: ?string
     * }
     */
    public function present(Sermon $sermon): array
    {
        $key = $this->cacheKey($sermon, 'full_present');

        if (isset($this->memoizedPresents[$key])) {
            /** @var array{audio_url: ?string, canonical_url: string, card_thumbnail_url: ?string, display_reference: ?string, duration_iso8601: ?string, formatted_duration: ?string, has_transcript: bool, human_date: string, preacher_image_url: ?string, preacher_name: ?string, preacher_url: ?string, public_url: string, series_url: ?string, thumbnail_url: ?string, transcript: ?string, transcript_url: ?string, video_url: ?string} */
            return $this->memoizedPresents[$key];
        }

        return $this->memoizedPresents[$key] = array_merge(
            $this->presentForList($sermon),
            ['transcript' => $this->transcriptReader->read($sermon)]
        );
    }

    public function publicUrl(Sermon $sermon): string
    {
        $value = $this->memoizedUrls[$this->cacheKey($sermon, 'public')] ??= $this->exposurePolicy->publicUrl($sermon);

        return (string) $value;
    }

    public function thumbnailUrl(Sermon $sermon): ?string
    {
        $key = $this->cacheKey($sermon, 'thumb');

        if (isset($this->memoizedUrls[$key])) {
            $value = $this->memoizedUrls[$key];

            return $value === self::MEMO_NULL ? null : (string) $value;
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
     *
     * Relation Loading: Always consults the loaded relation first to ensure
     * the most current state is returned, avoiding staleness when relations
     * transition from unloaded to loaded within a single request.
     */
    public function displayPreacherName(Sermon $sermon): ?string
    {
        // Use identity lookup for all paths (loaded or unloaded) to maximize hits
        $identityKey = $sermon->preacher_id !== null
            ? "id_{$sermon->preacher_id}"
            : (string) $sermon->preacher;

        if ($identityKey === '') {
            return null;
        }

        if (isset($this->memoizedPreacherNames[$identityKey])) {
            $value = $this->memoizedPreacherNames[$identityKey];

            return $value === self::MEMO_NULL ? null : (string) $value;
        }

        // If the relation is explicitly loaded, always use it as the source of truth
        if ($sermon->relationLoaded('preacherProfile') && $sermon->preacherProfile !== null) {
            return $this->memoizedPreacherNames[$identityKey] = $sermon->preacherProfile->name ?: self::MEMO_NULL;
        }

        $preacherName = trim((string) $sermon->preacher);

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
     *
     * Relation Loading: Always consults the loaded relation first to ensure
     * the most current state is returned, avoiding staleness when relations
     * transition from unloaded to loaded within a single request.
     */
    public function displayReference(Sermon $sermon): ?string
    {
        // Use identity lookup for all paths (loaded or unloaded) to maximize hits
        $identityKey = $sermon->scripture_passage_id !== null
            ? "id_{$sermon->scripture_passage_id}"
            : (string) $sermon->reference;

        if ($identityKey === '') {
            return null;
        }

        if (isset($this->memoizedReferences[$identityKey])) {
            $value = $this->memoizedReferences[$identityKey];

            return $value === self::MEMO_NULL ? null : (string) $value;
        }

        // If the relation is explicitly loaded, always use it as the source of truth
        if ($sermon->relationLoaded('scripturePassage') && $sermon->scripturePassage instanceof ScripturePassage) {
            $displayReference = $sermon->scripturePassage->display_reference ?: $sermon->scripturePassage->normalized_reference;

            if (trim((string) $displayReference) !== '') {
                return $this->memoizedReferences[$identityKey] = (string) $displayReference;
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

        $preacherName = (string) ($this->displayPreacherName($sermon) ?? 'Unknown preacher');
        $humanDate = $this->humanDate($sermon);
        $base = "Listen to '{$sermon->title}' by {$preacherName} preached on {$humanDate}";

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
            $value = $this->memoizedUrls[$key];

            return $value === self::MEMO_NULL ? null : (string) $value;
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
            $timestamp = $sermon->updated_at?->getTimestamp() ?? 0;
            $this->memoizedSermonKeys[$id] = "{$id}_{$timestamp}";
        }

        return "{$type}_{$this->memoizedSermonKeys[$id]}";
    }
}
