<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Models\ScripturePassage;
use App\Models\Sermon;
use App\Services\SermonExposurePolicy;
use App\Services\SermonStorageService;
use App\Services\SermonTranscriptReader;
use App\Support\SermonContentFormatter;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SermonViewPresenter
{
    /**
     * General memoization for scalar and nullable values.
     *
     * @var array<string, mixed>
     */
    private array $memoized = [];

    /**
     * Memoization for generated URLs.
     *
     * @var array<string, ?string>
     */
    private array $memoizedUrls = [];

    /**
     * Memoization for presented data arrays and collections.
     *
     * @var array<string, array<string, mixed>|array<int, array<string, mixed>>>
     */
    private array $memoizedPresents = [];

    /**
     * Memoization for formatted dates.
     *
     * @var array<int, array{human: string, iso: string, short: string}>
     */
    private array $memoizedDates = [];

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
     * Get the human-friendly formatted duration of the sermon (e.g. "1h 30m").
     */
    public function formattedDuration(Sermon $sermon): ?string
    {
        return SermonContentFormatter::humanDuration($this->durationInSeconds($sermon));
    }

    /**
     * Get the ISO 8601 duration string (e.g. PT45M) for the sermon.
     */
    public function durationIso8601(Sermon $sermon): ?string
    {
        return SermonContentFormatter::iso8601Duration($this->durationInSeconds($sermon));
    }

    /**
     * Normalize the float-cast `duration` column to an integer number of seconds.
     */
    private function durationInSeconds(Sermon $sermon): ?int
    {
        return $sermon->duration === null ? null : (int) $sermon->duration;
    }

    /**
     * Get various formatted date strings for the sermon.
     *
     * Performance Optimization: Memoizes date formatting results by timestamp
     * to avoid redundant object calls and string formatting across multiple
     * sermons sharing the same date in a listing. Returns human-friendly,
     * ISO 8601, and short display formats.
     *
     * @return array{human: string, iso: string, short: string}
     */
    public function formattedDates(Sermon $sermon): array
    {
        $timestamp = $sermon->date->getTimestamp();

        return $this->memoizedDates[$timestamp] ??= [
            'human' => $sermon->date->format('F j, Y'),
            'iso' => $sermon->date->toDateString(),
            'short' => $sermon->date->format('j F Y'),
        ];
    }

    /**
     * Get the human-friendly date of the sermon.
     */
    public function humanDate(Sermon $sermon): string
    {
        return $this->formattedDates($sermon)['human'];
    }

    /**
     * Clear all internal memoization caches.
     * Useful for long-running processes or tests.
     */
    public function clearInternalCaches(): void
    {
        $this->memoized = [];
        $this->memoizedUrls = [];
        $this->memoizedPresents = [];
        $this->memoizedDates = [];
        $this->computed = [];
    }

    public function audioUrl(Sermon $sermon): ?string
    {
        $key = $this->cacheKey($sermon, 'audio');

        if (isset($this->computed[$key])) {
            return $this->memoizedUrls[$key];
        }

        $url = filled($sermon->audio_file_path)
            ? $this->storageService->getAudioDeliveryUrl($sermon)
            : null;

        $this->computed[$key] = true;

        return $this->memoizedUrls[$key] = $url;
    }

    /**
     * Get the plain text representation of the sermon points.
     */
    public function plainTextOutline(Sermon $sermon): ?string
    {
        return SermonContentFormatter::plainTextOutline($sermon->points);
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

        $keyAuth = "img_auth_{$identityKey}";
        if (isset($this->computed[$keyAuth])) {
            return $this->memoized["img_{$identityKey}"];
        }

        // If the relation is explicitly loaded, use it as the source of truth and update memo
        if ($sermon->relationLoaded('preacherProfile')) {
            $url = $sermon->preacherProfile?->profile_image_url;
            $this->computed[$keyAuth] = true;
            $this->computed["img_{$identityKey}"] = true;
            $this->memoized["img_{$identityKey}"] = $url;

            return $url;
        }

        $key = "img_{$identityKey}";
        if (isset($this->computed[$key])) {
            return $this->memoized[$key];
        }

        return null;
    }

    public function canonicalUrl(Sermon $sermon): string
    {
        $key = $this->cacheKey($sermon, 'canonical');

        if (isset($this->computed[$key])) {
            /** @var string */
            return $this->memoizedUrls[$key];
        }

        $url = $this->exposurePolicy->canonicalUrl($sermon);

        $this->computed[$key] = true;

        return $this->memoizedUrls[$key] = $url;
    }

    /**
     * Get the card variant thumbnail URL for a sermon.
     *
     * Performance Optimization: Implements fallback logic to use the primary
     * thumbnail path if metadata is not loaded (e.g. in listings) to avoid
     * N+1 queries for large JSON metadata.
     */
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

            if (! isset($sermon->getAttributes()['thumbnail_metadata'])) {
                return null;
            }

            if (! $sermon->hasPlainThumbnail()) {
                return null;
            }

            return $this->storageService->getCardThumbnailDeliveryUrl($sermon);
        })();

        return $this->memoizedUrls[$key] = $url;
    }

    /**
     * Get the plain variant thumbnail URL for a sermon.
     *
     * Performance Optimization: Implements fallback logic to use the primary
     * thumbnail path if metadata is not loaded (e.g. in listings) to avoid
     * N+1 queries for large JSON metadata.
     */
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

            // Fallback for listings where metadata is not selected: use primary thumbnail
            if (! isset($sermon->getAttributes()['thumbnail_metadata']) && $sermon->hasThumbnail()) {
                return $this->thumbnailUrl($sermon);
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

        $keyAuth = "url_auth_{$identityKey}";
        if (isset($this->computed[$keyAuth])) {
            return $this->memoizedUrls["preacher_{$identityKey}"];
        }

        // If the relation is explicitly loaded, use it as the source of truth and update memo
        if ($sermon->relationLoaded('preacherProfile') && $sermon->preacherProfile !== null) {
            $url = route('sermons.preacher', ['preacher' => $sermon->preacherProfile->slug]);
            $this->computed[$keyAuth] = true;
            $this->computed["url_{$identityKey}"] = true;
            $this->memoizedUrls["preacher_{$identityKey}"] = $url;

            return $url;
        }

        $key = "url_{$identityKey}";
        if (isset($this->computed[$key])) {
            return $this->memoizedUrls["preacher_{$identityKey}"];
        }

        // Fall back to the unloaded path: derive URL from displayPreacherName
        $preacherName = $this->displayPreacherName($sermon);

        $url = filled($preacherName)
            ? route('sermons.preacher', ['preacher' => $this->slug($preacherName)])
            : null;

        $this->computed[$key] = true;
        $this->memoizedUrls["preacher_{$identityKey}"] = $url;

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

        $key = "service_label_{$service->value}";

        if (isset($this->computed[$key])) {
            /** @var string */
            return $this->memoized[$key];
        }

        $label = $service->label();

        $this->computed[$key] = true;

        return $this->memoized[$key] = $label;
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

        $key = "series_url_{$sermon->series}";

        if (isset($this->computed[$key])) {
            return $this->memoizedUrls[$key];
        }

        $url = route('sermons.series.show', ['series' => $this->slug($sermon->series)]);

        $this->computed[$key] = true;

        return $this->memoizedUrls[$key] = $url;
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

        if (isset($this->computed[$key])) {
            /** @var array{audio_url: ?string, display_reference: ?string, duration_iso8601: ?string, formatted_duration: ?string, human_date: string, preacher_image_url: ?string, preacher_name: ?string, preacher_url: ?string, series_url: ?string, thumbnail_url: ?string, video_url: ?string} */
            return $this->memoizedPresents[$key];
        }

        $presented = [
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

        $this->computed[$key] = true;

        return $this->memoizedPresents[$key] = $presented;
    }

    /**
     * Bulk present a collection of sermons for list display.
     *
     * Performance Optimization: Iterates through the collection once to populate
     * all internal memoization caches. This reduces overhead when the same
     * collection is processed multiple times (e.g. for both UI and JSON-LD).
     *
     * @param  Collection<int, Sermon>  $sermons
     * @return array<int, array<string, mixed>>
     */
    public function presentCollection(Collection $sermons): array
    {
        if ($sermons->isEmpty()) {
            return [];
        }

        $ids = $sermons->pluck('id')->all();
        sort($ids);
        $collectionKey = 'col_'.sha1(implode('|', $ids));

        if (isset($this->computed[$collectionKey])) {
            /** @var array<int, array<string, mixed>> */
            return $this->memoizedPresents[$collectionKey];
        }

        $presented = $sermons
            ->keyBy('id')
            ->map(fn (Sermon $sermon) => $this->presentForList($sermon))
            ->all();

        $this->computed[$collectionKey] = true;

        return $this->memoizedPresents[$collectionKey] = $presented;
    }

    /**
     * Pre-warm the internal memoization caches for fields used in admin listings.
     *
     * Performance Optimization: Only computes preacher names and scripture references
     * to avoid triggering lazy-loading of media-related columns that are typically
     * excluded from admin list queries.
     *
     * @param  Collection<int, Sermon>  $sermons
     */
    public function preWarmForAdminList(Collection $sermons): void
    {
        if ($sermons->isEmpty()) {
            return;
        }

        foreach ($sermons as $sermon) {
            $this->displayPreacherName($sermon);
            $this->displayReference($sermon);
            $this->formattedDates($sermon);
            $this->serviceLabel($sermon);
        }
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

        if (isset($this->computed[$key])) {
            /** @var array{audio_url: ?string, canonical_url: string, card_thumbnail_url: ?string, date_iso: string, date_string: string, display_reference: ?string, duration_iso8601: ?string, formatted_duration: ?string, has_transcript: bool, human_date: string, plain_thumbnail_url: ?string, preacher_image_url: ?string, preacher_name: ?string, preacher_url: ?string, public_url: string, series_url: ?string, service_label: ?string, thumbnail_url: ?string, transcript_url: ?string, video_url: ?string} */
            return $this->memoizedPresents[$key];
        }

        $hasTranscript = $sermon->hasTranscript();
        $dates = $this->formattedDates($sermon);

        $presented = [
            'audio_url' => $this->audioUrl($sermon),
            'canonical_url' => $this->canonicalUrl($sermon),
            'card_thumbnail_url' => $this->cardThumbnailUrl($sermon),
            'date_iso' => $dates['iso'],
            'date_string' => $dates['short'],
            'display_reference' => $this->displayReference($sermon),
            'duration_iso8601' => $this->durationIso8601($sermon),
            'formatted_duration' => $this->formattedDuration($sermon),
            'has_transcript' => $hasTranscript,
            'human_date' => $dates['human'],
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

        $this->computed[$key] = true;

        return $this->memoizedPresents[$key] = $presented;
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

        if (isset($this->computed[$key])) {
            /** @var array{audio_url: ?string, canonical_url: string, card_thumbnail_url: ?string, date_iso: string, date_string: string, display_reference: ?string, duration_iso8601: ?string, formatted_duration: ?string, has_transcript: bool, human_date: string, plain_thumbnail_url: ?string, preacher_image_url: ?string, preacher_name: ?string, preacher_url: ?string, public_url: string, series_url: ?string, service_label: ?string, thumbnail_url: ?string, transcript: ?string, transcript_url: ?string, plain_text_outline: ?string, video_url: ?string} */
            return $this->memoizedPresents[$key];
        }

        $presented = array_merge(
            $this->presentForList($sermon),
            [
                'transcript' => $this->transcriptReader->read($sermon),
                'plain_text_outline' => $this->plainTextOutline($sermon),
            ]
        );

        $this->computed[$key] = true;

        return $this->memoizedPresents[$key] = $presented;
    }

    public function publicUrl(Sermon $sermon): string
    {
        $key = $this->cacheKey($sermon, 'public');

        if (isset($this->computed[$key])) {
            /** @var string */
            return $this->memoizedUrls[$key];
        }

        $url = $this->exposurePolicy->publicUrl($sermon);

        $this->computed[$key] = true;

        return $this->memoizedUrls[$key] = $url;
    }

    public function thumbnailUrl(Sermon $sermon): ?string
    {
        $key = $this->cacheKey($sermon, 'thumb');

        if (isset($this->computed[$key])) {
            return $this->memoizedUrls[$key];
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

        $this->computed[$key] = true;

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
        $identityKey = $sermon->preacher_id !== null
            ? "id_{$sermon->preacher_id}"
            : (string) $sermon->preacher;

        if ($identityKey === '') {
            return null;
        }

        $keyAuth = "name_auth_{$identityKey}";
        if (isset($this->computed[$keyAuth])) {
            return $this->memoized["name_{$identityKey}"];
        }

        // If the relation is explicitly loaded, use it as the source of truth and update memo
        if ($sermon->relationLoaded('preacherProfile') && $sermon->preacherProfile !== null) {
            $name = $sermon->preacherProfile->name ?: null;
            $this->computed[$keyAuth] = true;
            $this->computed["name_{$identityKey}"] = true;

            return $this->memoized["name_{$identityKey}"] = $name;
        }

        $key = "name_{$identityKey}";
        if (isset($this->computed[$key])) {
            return $this->memoized[$key];
        }

        // Fall back to the unloaded path: cache the string fallback
        $preacherName = trim((string) $sermon->preacher);

        $this->computed[$key] = true;

        if ($preacherName === '') {
            return $this->memoized[$key] = null;
        }

        return $this->memoized[$key] = $preacherName;
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
        $identityKey = $sermon->scripture_passage_id !== null
            ? "id_{$sermon->scripture_passage_id}"
            : (string) $sermon->reference;

        if ($identityKey === '') {
            return null;
        }

        $keyAuth = "ref_auth_{$identityKey}";
        if (isset($this->computed[$keyAuth])) {
            return $this->memoized["ref_{$identityKey}"];
        }

        // If the relation is explicitly loaded, use it as the source of truth and update memo
        if ($sermon->relationLoaded('scripturePassage') && $sermon->scripturePassage instanceof ScripturePassage) {
            $displayReference = $sermon->scripturePassage->display_reference ?: $sermon->scripturePassage->normalized_reference;

            if (trim((string) $displayReference) !== '') {
                $this->computed[$keyAuth] = true;
                $this->computed["ref_{$identityKey}"] = true;

                return $this->memoized["ref_{$identityKey}"] = $displayReference;
            }
        }

        $key = "ref_{$identityKey}";
        if (isset($this->computed[$key])) {
            return $this->memoized[$key];
        }

        // Fall back to the unloaded path: cache the string fallback
        $reference = trim((string) $sermon->reference);

        $this->computed[$key] = true;

        if ($reference === '') {
            return $this->memoized[$key] = null;
        }

        return $this->memoized[$key] = $reference;
    }

    /**
     * Generate the SEO meta description for a sermon.
     *
     * Performance Optimization: Memoizes generated meta descriptions to avoid
     * redundant assembly logic for the same sermon within a single request.
     * Checks for existing database-stored descriptions before falling back
     * to dynamic generation.
     */
    /**
     * Get the descriptive alt text for a sermon thumbnail image.
     */
    public function imageAlt(Sermon $sermon): string
    {
        $preacherName = $this->displayPreacherName($sermon);

        return 'Sermon: '.$sermon->title.($preacherName ? ' by '.$preacherName : '');
    }

    /**
     * Get the descriptive alt text for a Children's Corner talk thumbnail image.
     */
    public function childrensTalkImageAlt(Sermon $sermon): string
    {
        $preacherName = $this->displayPreacherName($sermon);

        return "Children's Corner: ".$sermon->title.($preacherName ? ' by '.$preacherName : '');
    }

    public function metaDescription(Sermon $sermon): string
    {
        $key = $this->cacheKey($sermon, 'meta_desc');

        if (isset($this->computed[$key])) {
            /** @var string */
            return $this->memoized[$key];
        }

        $attributes = $sermon->getAttributes();
        if (filled($attributes['meta_description'] ?? null)) {
            $this->computed[$key] = true;

            return $this->memoized[$key] = (string) $attributes['meta_description'];
        }

        $preacherName = $this->displayPreacherName($sermon) ?? 'Unknown preacher';

        $hasVideo = $this->exposurePolicy->shouldExposeVideo($sermon);
        $hasAudio = filled($sermon->audio_file_path);

        $verb = match (true) {
            $hasVideo && $hasAudio => 'Watch or listen to',
            $hasVideo => 'Watch',
            default => 'Listen to',
        };

        $serviceLabel = $this->serviceLabel($sermon);
        $servicePhrase = $serviceLabel ? " during our {$serviceLabel} service" : '';

        $base = "{$verb} '{$sermon->title}' by {$preacherName} preached at Crockenhill Baptist Church on {$this->humanDate($sermon)}{$servicePhrase}";

        if ($reference = $this->displayReference($sermon)) {
            $base .= " - {$reference}";
        }

        if ($sermon->series) {
            $base .= " (Part of our {$sermon->series} series)";
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

        $this->computed[$key] = true;

        if ($remaining > 0) {
            return $this->memoized[$key] = $base.'. '.Str::limit($summary, $remaining);
        }

        return $this->memoized[$key] = Str::limit($base, 155);
    }

    public function videoUrl(Sermon $sermon): ?string
    {
        $key = $this->cacheKey($sermon, 'video');

        if (isset($this->computed[$key])) {
            return $this->memoizedUrls[$key];
        }

        $url = (function () use ($sermon) {
            if (! $this->exposurePolicy->shouldExposeVideo($sermon)) {
                return null;
            }

            return $this->storageService->getVideoDeliveryUrl($sermon);
        })();

        $this->computed[$key] = true;

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
        $key = "slug_{$value}";

        if (isset($this->computed[$key])) {
            /** @var string */
            return $this->memoized[$key];
        }

        $slug = Str::slug($value);

        $this->computed[$key] = true;

        return $this->memoized[$key] = $slug;
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

        $key = "sermon_key_{$id}";
        if (! isset($this->computed[$key])) {
            $timestamp = $sermon->updated_at?->getTimestamp() ?? 0;
            $this->computed[$key] = true;
            $this->memoized[$key] = "{$id}_{$timestamp}";
        }

        /** @var string */
        $sermonKey = $this->memoized[$key];

        return $type.'_'.$sermonKey;
    }
}
