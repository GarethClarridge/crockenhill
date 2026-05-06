<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Models\ScripturePassage;
use App\Models\Sermon;
use App\Services\SermonExposurePolicy;
use App\Services\SermonStorageService;
use App\Services\SermonTranscriptReader;
use Carbon\CarbonInterval;
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
    private array $memoizedSeriesUrls = [];

    /**
     * @var array<int|string, ?string>
     */
    private array $memoizedPreacherUrls = [];

    /**
     * @var array<int|string, ?string>
     */
    private array $memoizedPreacherNames = [];

    /**
     * @var array<int|string, ?string>
     */
    private array $memoizedPreacherImageUrls = [];

    /**
     * @var array<string, ?string>
     */
    private array $memoizedServiceLabels = [];

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

    /**
     * Tracks which keys have been computed, allowing null to be a legitimate cached result.
     *
     * @var array<string, true>
     */
    private array $computed = [];

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

        if (isset($this->computed[$key])) {
            return $this->memoizedDurations[$key];
        }

        $this->computed[$key] = true;

        if ($sermon->duration === null || $sermon->duration <= 0) {
            return $this->memoizedDurations[$key] = null;
        }

        $seconds = (int) $sermon->duration;
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        return $this->memoizedDurations[$key] = $hours > 0
            ? "{$hours}h {$minutes}m"
            : "{$minutes}m";
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

        if (isset($this->computed[$key])) {
            return $this->memoizedIsoDurations[$key];
        }

        $this->computed[$key] = true;

        if ($sermon->duration === null || $sermon->duration <= 0) {
            return $this->memoizedIsoDurations[$key] = null;
        }

        return $this->memoizedIsoDurations[$key] = CarbonInterval::seconds($sermon->duration)->cascade()->spec();
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
        $this->memoizedPreacherUrls = [];
        $this->memoizedPreacherNames = [];
        $this->memoizedPreacherImageUrls = [];
        $this->memoizedServiceLabels = [];
        $this->memoizedReferences = [];
        $this->memoizedDurations = [];
        $this->memoizedPresents = [];
        $this->memoizedTimestamps = [];
        $this->memoizedSermonKeys = [];
        $this->memoizedHumanDates = [];
        $this->memoizedSlugs = [];
        $this->memoizedMetaDescriptions = [];
        $this->memoizedIsoDurations = [];
        $this->computed = [];
    }

    public function audioUrl(Sermon $sermon): ?string
    {
        $key = $this->cacheKey($sermon, 'audio');

        if (isset($this->computed[$key])) {
            return $this->memoizedUrls[$key];
        }

        $this->computed[$key] = true;

        $url = filled($sermon->audio_file_path)
            ? $this->storageService->getAudioDeliveryUrl($sermon)
            : null;

        return $this->memoizedUrls[$key] = $url;
    }

    /**
     * Get the plain text representation of the sermon points.
     */
    public function plainTextOutline(Sermon $sermon): ?string
    {
        $points = $sermon->points;

        if (! is_array($points) || $points === []) {
            return null;
        }

        $outline = '';
        $counter = 1;

        foreach ($points as $pointItem) {
            $mainText = '';
            $subLines = [];

            if (is_array($pointItem)) {
                $mainText = (isset($pointItem['point']) && is_scalar($pointItem['point'])) ? trim((string) $pointItem['point']) : '';
                $subPoints = (isset($pointItem['sub_points']) && is_array($pointItem['sub_points'])) ? $pointItem['sub_points'] : [];

                foreach ($subPoints as $subPoint) {
                    if (is_scalar($subPoint) && filled($subPoint)) {
                        $subLines[] = '   - '.trim((string) $subPoint);
                    }
                }
            } elseif (is_scalar($pointItem)) {
                $mainText = trim((string) $pointItem);
            }

            if ($mainText !== '' || count($subLines) > 0) {
                $outline .= "{$counter}. ".($mainText !== '' ? $mainText : '(Untitled point)')."\n";

                foreach ($subLines as $subLine) {
                    $outline .= "{$subLine}\n";
                }

                $counter++;
            }
        }

        return trim($outline) ?: null;
    }

    /**
     * Get the preacher's profile image URL.
     *
     * Performance Optimization: Memoizes preacher image URL lookups by identity
     * (profile ID or name string) instead of sermon ID. This avoids redundant
     * lookups across multiple sermons in a listing by the same preacher.
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

        // If the relation is explicitly loaded, always use it as the source of truth and update memo
        if ($sermon->relationLoaded('preacherProfile')) {
            $url = $sermon->preacherProfile?->profile_image_url;
            $this->computed["img_{$identityKey}"] = true;
            $this->memoizedPreacherImageUrls[$identityKey] = $url;

            return $url;
        }

        if (isset($this->computed["img_{$identityKey}"])) {
            return $this->memoizedPreacherImageUrls[$identityKey];
        }

        return null;
    }

    public function canonicalUrl(Sermon $sermon): string
    {
        return $this->memoizedUrls[$this->cacheKey($sermon, 'canonical')] ??= $this->exposurePolicy->canonicalUrl($sermon);
    }

    public function cardThumbnailUrl(Sermon $sermon): ?string
    {
        $key = $this->cacheKey($sermon, 'card_thumb');

        if (isset($this->computed[$key])) {
            return $this->memoizedUrls[$key];
        }

        $this->computed[$key] = true;

        $url = (function () use ($sermon) {
            if (! $this->exposurePolicy->shouldExposeThumbnail($sermon)) {
                return null;
            }

            if (! $sermon->hasPlainThumbnail()) {
                return null;
            }

            return $this->storageService->getCardThumbnailDeliveryUrl($sermon);
        })();

        return $this->memoizedUrls[$key] = $url;
    }

    public function plainThumbnailUrl(Sermon $sermon): ?string
    {
        $key = $this->cacheKey($sermon, 'plain_thumb');

        if (isset($this->computed[$key])) {
            return $this->memoizedUrls[$key];
        }

        $this->computed[$key] = true;

        $url = (function () use ($sermon) {
            if (! $this->exposurePolicy->shouldExposeThumbnail($sermon)) {
                return null;
            }

            if (! $sermon->hasPlainThumbnail()) {
                return null;
            }

            return $this->storageService->getPlainThumbnailDeliveryUrl($sermon);
        })();

        return $this->memoizedUrls[$key] = $url;
    }

    /**
     * Get the preacher's profile URL.
     *
     * Performance Optimization: Memoizes preacher URL lookups by identity
     * (profile ID or name string) instead of sermon ID. This avoids redundant
     * lookups across multiple sermons in a listing by the same preacher.
     *
     * Relation Loading: Always consults the loaded relation first to ensure
     * the most current state is returned.
     */
    public function preacherUrl(Sermon $sermon): ?string
    {
        $identityKey = $sermon->preacher_id !== null
            ? "id_{$sermon->preacher_id}"
            : (string) $sermon->preacher;

        if ($identityKey === '') {
            return null;
        }

        // If the relation is explicitly loaded, always use it as the source of truth and update memo
        if ($sermon->relationLoaded('preacherProfile') && $sermon->preacherProfile !== null) {
            $url = route('sermons.preacher', ['preacher' => $sermon->preacherProfile->slug]);
            $this->computed["url_{$identityKey}"] = true;
            $this->memoizedPreacherUrls[$identityKey] = $url;

            return $url;
        }

        if (isset($this->computed["url_{$identityKey}"])) {
            return $this->memoizedPreacherUrls[$identityKey];
        }

        // Fall back to the unloaded path: derive URL from displayPreacherName
        $preacherName = $this->displayPreacherName($sermon);

        $url = filled($preacherName)
            ? route('sermons.preacher', ['preacher' => $this->slug($preacherName)])
            : null;

        $this->computed["url_{$identityKey}"] = true;
        $this->memoizedPreacherUrls[$identityKey] = $url;

        return $url;
    }

    /**
     * Get the label for the sermon's service.
     *
     * Performance Optimization: Memoizes service labels based on the enum value
     * to avoid redundant method calls and string formatting across a listing.
     *
     * Robustness: Handles the SermonService enum with a safety check to ensure
     * compatibility, matching the logic previously found in Blade templates.
     */
    public function serviceLabel(Sermon $sermon): ?string
    {
        $service = $sermon->service;

        if ($service === null) {
            return null;
        }

        return $this->memoizedServiceLabels[$service->value] ??= $service->label();
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

        $key = "series_{$sermon->series}";

        if (isset($this->computed[$key])) {
            return $this->memoizedSeriesUrls[$sermon->series];
        }

        $this->computed[$key] = true;

        return $this->memoizedSeriesUrls[$sermon->series] = route('sermons.series.show', ['series' => $this->slug($sermon->series)]);
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
     *     video_url: ?string
     * }
     */
    public function presentForApi(Sermon $sermon): array
    {
        $key = $this->cacheKey($sermon, 'api_present');

        /** @var array{audio_url: ?string, display_reference: ?string, duration_iso8601: ?string, formatted_duration: ?string, human_date: string, preacher_image_url: ?string, preacher_name: ?string, preacher_url: ?string, series_url: ?string, thumbnail_url: ?string, video_url: ?string} */
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
            'video_url' => $this->videoUrl($sermon),
        ];
    }

    /**
     * @return array{
     *     audio_url: ?string,
     *     canonical_url: string,
     *     card_thumbnail_url: ?string,
     *     date_iso: string,
     *     date_string: string,
     *     display_reference: ?string,
     *     duration_iso8601: ?string,
     *     formatted_duration: ?string,
     *     has_transcript: bool,
     *     human_date: string,
     *     plain_thumbnail_url: ?string,
     *     preacher_image_url: ?string,
     *     preacher_name: ?string,
     *     preacher_url: ?string,
     *     public_url: string,
     *     series_url: ?string,
     *     service_label: ?string,
     *     thumbnail_url: ?string,
     *     transcript_url: ?string,
     *     video_url: ?string
     * }
     */
    public function presentForList(Sermon $sermon): array
    {
        $key = $this->cacheKey($sermon, 'list_present');

        if (isset($this->memoizedPresents[$key])) {
            /** @var array{audio_url: ?string, canonical_url: string, card_thumbnail_url: ?string, date_iso: string, date_string: string, display_reference: ?string, duration_iso8601: ?string, formatted_duration: ?string, has_transcript: bool, human_date: string, plain_thumbnail_url: ?string, preacher_image_url: ?string, preacher_name: ?string, preacher_url: ?string, public_url: string, series_url: ?string, service_label: ?string, thumbnail_url: ?string, transcript_url: ?string, video_url: ?string} */
            return $this->memoizedPresents[$key];
        }

        $hasTranscript = $sermon->hasTranscript();

        $date = $sermon->date;
        $dateIso = $date->toDateString();
        $dateString = $date->format('j F Y');

        return $this->memoizedPresents[$key] = [
            'audio_url' => $this->audioUrl($sermon),
            'canonical_url' => $this->canonicalUrl($sermon),
            'card_thumbnail_url' => $this->cardThumbnailUrl($sermon),
            'date_iso' => $dateIso,
            'date_string' => $dateString,
            'display_reference' => $this->displayReference($sermon),
            'duration_iso8601' => $this->durationIso8601($sermon),
            'formatted_duration' => $this->formattedDuration($sermon),
            'has_transcript' => $hasTranscript,
            'human_date' => $this->humanDate($sermon),
            'plain_thumbnail_url' => $this->plainThumbnailUrl($sermon),
            'preacher_image_url' => $this->preacherImageUrl($sermon),
            'preacher_name' => $this->displayPreacherName($sermon),
            'preacher_url' => $this->preacherUrl($sermon),
            'public_url' => $this->publicUrl($sermon),
            'series_url' => $this->seriesUrl($sermon),
            'service_label' => $this->serviceLabel($sermon),
            'thumbnail_url' => $this->thumbnailUrl($sermon),
            'transcript_url' => $hasTranscript ? route('sermons.transcript', ['sermon' => $sermon->slug]) : null,
            'video_url' => $this->videoUrl($sermon),
        ];
    }

    /**
     * @return array{
     *     audio_url: ?string,
     *     canonical_url: string,
     *     card_thumbnail_url: ?string,
     *     date_iso: string,
     *     date_string: string,
     *     display_reference: ?string,
     *     duration_iso8601: ?string,
     *     formatted_duration: ?string,
     *     has_transcript: bool,
     *     human_date: string,
     *     plain_thumbnail_url: ?string,
     *     preacher_image_url: ?string,
     *     preacher_name: ?string,
     *     preacher_url: ?string,
     *     public_url: string,
     *     series_url: ?string,
     *     service_label: ?string,
     *     thumbnail_url: ?string,
     *     transcript: ?string,
     *     transcript_url: ?string,
     *     plain_text_outline: ?string,
     *     video_url: ?string
     * }
     */
    public function present(Sermon $sermon): array
    {
        $key = $this->cacheKey($sermon, 'full_present');

        if (isset($this->memoizedPresents[$key])) {
            /** @var array{audio_url: ?string, canonical_url: string, card_thumbnail_url: ?string, date_iso: string, date_string: string, display_reference: ?string, duration_iso8601: ?string, formatted_duration: ?string, has_transcript: bool, human_date: string, plain_thumbnail_url: ?string, preacher_image_url: ?string, preacher_name: ?string, preacher_url: ?string, public_url: string, series_url: ?string, service_label: ?string, thumbnail_url: ?string, transcript: ?string, transcript_url: ?string, plain_text_outline: ?string, video_url: ?string} */
            return $this->memoizedPresents[$key];
        }

        return $this->memoizedPresents[$key] = array_merge(
            $this->presentForList($sermon),
            [
                'transcript' => $this->transcriptReader->read($sermon),
                'plain_text_outline' => $this->plainTextOutline($sermon),
            ]
        );
    }

    public function publicUrl(Sermon $sermon): string
    {
        return $this->memoizedUrls[$this->cacheKey($sermon, 'public')] ??= $this->exposurePolicy->publicUrl($sermon);
    }

    public function thumbnailUrl(Sermon $sermon): ?string
    {
        $key = $this->cacheKey($sermon, 'thumb');

        if (isset($this->computed[$key])) {
            return $this->memoizedUrls[$key];
        }

        $this->computed[$key] = true;

        $url = (function () use ($sermon) {
            if (! $this->exposurePolicy->shouldExposeThumbnail($sermon)) {
                return null;
            }

            if (! $sermon->hasThumbnail()) {
                return null;
            }

            return $this->storageService->getThumbnailDeliveryUrl($sermon);
        })();

        return $this->memoizedUrls[$key] = $url;
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
        // If the relation is explicitly loaded, always use it as the source of truth
        if ($sermon->relationLoaded('preacherProfile') && $sermon->preacherProfile !== null) {
            return $sermon->preacherProfile->name ?: null;
        }

        // Fall back to the unloaded path: cache the string fallback
        $identityKey = $sermon->preacher_id !== null
            ? "id_{$sermon->preacher_id}"
            : (string) $sermon->preacher;

        if ($identityKey === '') {
            return null;
        }

        if (isset($this->computed["name_{$identityKey}"])) {
            return $this->memoizedPreacherNames[$identityKey];
        }

        $preacherName = trim((string) $sermon->preacher);

        $this->computed["name_{$identityKey}"] = true;

        if ($preacherName === '') {
            return $this->memoizedPreacherNames[$identityKey] = null;
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
        // If the relation is explicitly loaded, always use it as the source of truth
        if ($sermon->relationLoaded('scripturePassage') && $sermon->scripturePassage instanceof ScripturePassage) {
            $displayReference = $sermon->scripturePassage->display_reference ?: $sermon->scripturePassage->normalized_reference;

            if (trim((string) $displayReference) !== '') {
                return $displayReference;
            }
        }

        // Fall back to the unloaded path: cache the string fallback
        $identityKey = $sermon->scripture_passage_id !== null
            ? "id_{$sermon->scripture_passage_id}"
            : (string) $sermon->reference;

        if ($identityKey === '') {
            return null;
        }

        if (isset($this->computed["ref_{$identityKey}"])) {
            return $this->memoizedReferences[$identityKey];
        }

        $reference = trim((string) $sermon->reference);

        $this->computed["ref_{$identityKey}"] = true;

        if ($reference === '') {
            return $this->memoizedReferences[$identityKey] = null;
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

        if (isset($this->computed[$key])) {
            return $this->memoizedUrls[$key];
        }

        $this->computed[$key] = true;

        $url = (function () use ($sermon) {
            if (! $this->exposurePolicy->shouldExposeVideo($sermon)) {
                return null;
            }

            return $this->storageService->getVideoDeliveryUrl($sermon);
        })();

        return $this->memoizedUrls[$key] = $url;
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

        $this->memoizedTimestamps[$id] ??= $sermon->updated_at?->getTimestamp() ?? 0;
        $this->memoizedSermonKeys[$id] ??= "{$id}_{$this->memoizedTimestamps[$id]}";

        return "{$type}_{$this->memoizedSermonKeys[$id]}";
    }
}
