<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Models\ScripturePassage;
use App\Models\Sermon;
use App\Services\Media\Audio\SermonTranscriptReader;
use App\Services\Sermon\SermonExposurePolicy;
use App\Services\Sermon\SermonStorageService;
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
     * Tracks which keys have been computed, allowing null to be a legitimate cached result.
     *
     * @var array<string, true>
     */
    private array $computed = [];

    /**
     * Builds the SEO/meta surface; defaults to one backed by the exposure policy.
     */
    private readonly SermonMetaPresenter $metaPresenter;

    /**
     * Builds media and page URLs; defaults to one backed by the storage and
     * exposure services.
     */
    private readonly SermonUrlBuilder $urlBuilder;

    public function __construct(
        SermonExposurePolicy $exposurePolicy,
        SermonStorageService $storageService,
        private readonly SermonTranscriptReader $transcriptReader,
        private readonly SermonPresentationAssembler $assembler = new SermonPresentationAssembler,
        private readonly SermonDateFormatter $dateFormatter = new SermonDateFormatter,
        ?SermonMetaPresenter $metaPresenter = null,
        ?SermonUrlBuilder $urlBuilder = null,
    ) {
        $this->metaPresenter = $metaPresenter ?? new SermonMetaPresenter($exposurePolicy);
        $this->urlBuilder = $urlBuilder ?? new SermonUrlBuilder($storageService, $exposurePolicy);
    }

    /**
     * Get the human-friendly formatted duration of the sermon (e.g. "1h 30m").
     */
    public function formattedDuration(Sermon $sermon): ?string
    {
        return $this->dateFormatter->formattedDuration($sermon);
    }

    /**
     * Get the ISO 8601 duration string (e.g. PT45M) for the sermon.
     */
    public function durationIso8601(Sermon $sermon): ?string
    {
        return $this->dateFormatter->durationIso8601($sermon);
    }

    /**
     * Get various formatted date strings for the sermon.
     *
     * @return array{human: string, iso: string, short: string}
     */
    public function formattedDates(Sermon $sermon): array
    {
        return $this->dateFormatter->formattedDates($sermon);
    }

    /**
     * Get the human-friendly date of the sermon.
     */
    public function humanDate(Sermon $sermon): string
    {
        return $this->dateFormatter->humanDate($sermon);
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
        $this->computed = [];
        $this->dateFormatter->clearCache();
    }

    public function audioUrl(Sermon $sermon): ?string
    {
        return $this->memoize($sermon, 'audio', 'memoizedUrls', fn (): ?string => $this->urlBuilder->audioUrl($this, $sermon));
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
        return $this->resolvePreacherAttribute(
            $sermon,
            'img',
            store: 'memoized',
            // The image URL resolves the moment the relation is loaded, even when
            // the profile is null (it simply yields a null URL).
            fromLoaded: static fn (Sermon $sermon): array => [true, $sermon->preacherProfile?->profile_image_url],
            fromUnloaded: static fn (Sermon $sermon): ?string => null,
        );
    }

    public function canonicalUrl(Sermon $sermon): string
    {
        return $this->memoize($sermon, 'canonical', 'memoizedUrls', fn (): string => $this->urlBuilder->canonicalUrl($this, $sermon));
    }

    /**
     * Get the card variant thumbnail URL for a sermon.
     */
    public function cardThumbnailUrl(Sermon $sermon): ?string
    {
        return $this->memoize($sermon, 'card_thumb', 'memoizedUrls', fn (): ?string => $this->urlBuilder->cardThumbnailUrl($this, $sermon));
    }

    /**
     * Get the plain variant thumbnail URL for a sermon.
     */
    public function plainThumbnailUrl(Sermon $sermon): ?string
    {
        return $this->memoize($sermon, 'plain_thumb', 'memoizedUrls', fn (): ?string => $this->urlBuilder->plainThumbnailUrl($this, $sermon));
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
        return $this->resolvePreacherAttribute(
            $sermon,
            'url',
            store: 'memoizedUrls',
            // Only a non-null loaded profile yields a slug-based URL; otherwise
            // fall through to the name-derived fallback.
            fromLoaded: function (Sermon $sermon): array {
                if ($sermon->preacherProfile === null) {
                    return [false, null];
                }

                return [true, route('sermons.preacher', ['preacher' => $sermon->preacherProfile->slug])];
            },
            fromUnloaded: function (Sermon $sermon): ?string {
                $preacherName = $this->displayPreacherName($sermon);

                return filled($preacherName)
                    ? route('sermons.preacher', ['preacher' => $this->slug($preacherName)])
                    : null;
            },
            valueKey: 'preacher',
        );
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
     * The field shape lives on SermonPresentationAssembler::forApi(); this method
     * adds only request-level memoization of the assembled result.
     *
     * @return array{audio_url: ?string, display_reference: ?string, duration_iso8601: ?string, formatted_duration: ?string, human_date: string, preacher_image_url: ?string, preacher_name: ?string, preacher_url: ?string, series_url: ?string, thumbnail_url: ?string, video_url: ?string}
     */
    public function presentForApi(Sermon $sermon): array
    {
        return $this->memoize($sermon, 'api_present', 'memoizedPresents', fn (): array => $this->assembler->forApi($this, $sermon));
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
     * Present the full field set for a sermon listing.
     *
     * The field shape lives on SermonPresentationAssembler::forList(); this method
     * adds only request-level memoization of the assembled result.
     *
     * @return array{audio_url: ?string, canonical_url: string, card_thumbnail_url: ?string, date_iso: string, date_string: string, display_reference: ?string, duration_iso8601: ?string, formatted_duration: ?string, has_transcript: bool, human_date: string, plain_thumbnail_url: ?string, preacher_image_url: ?string, preacher_name: ?string, preacher_url: ?string, public_url: string, series_url: ?string, service_label: ?string, thumbnail_url: ?string, transcript_url: ?string, video_url: ?string}
     */
    public function presentForList(Sermon $sermon): array
    {
        return $this->memoize($sermon, 'list_present', 'memoizedPresents', fn (): array => $this->assembler->forList($this, $sermon));
    }

    /**
     * Present the full single-sermon view payload (the listing fields plus the
     * transcript and plain-text outline).
     *
     * The field shape lives on SermonPresentationAssembler::forFull(); this method
     * adds only request-level memoization of the assembled result.
     *
     * @return array{audio_url: ?string, canonical_url: string, card_thumbnail_url: ?string, date_iso: string, date_string: string, display_reference: ?string, duration_iso8601: ?string, formatted_duration: ?string, has_transcript: bool, human_date: string, plain_thumbnail_url: ?string, preacher_image_url: ?string, preacher_name: ?string, preacher_url: ?string, public_url: string, series_url: ?string, service_label: ?string, thumbnail_url: ?string, transcript: ?string, transcript_url: ?string, plain_text_outline: ?string, video_url: ?string}
     */
    public function present(Sermon $sermon): array
    {
        return $this->memoize($sermon, 'full_present', 'memoizedPresents', fn (): array => $this->assembler->forFull($this, $sermon));
    }

    public function publicUrl(Sermon $sermon): string
    {
        return $this->memoize($sermon, 'public', 'memoizedUrls', fn (): string => $this->urlBuilder->publicUrl($this, $sermon));
    }

    public function thumbnailUrl(Sermon $sermon): ?string
    {
        return $this->memoize($sermon, 'thumb', 'memoizedUrls', fn (): ?string => $this->urlBuilder->thumbnailUrl($this, $sermon));
    }

    public function transcript(Sermon $sermon): ?string
    {
        return $this->transcriptReader->read($sermon);
    }

    public function transcriptUrl(Sermon $sermon): ?string
    {
        return $this->memoize($sermon, 'transcript_url', 'memoizedUrls', fn (): ?string => $this->urlBuilder->transcriptUrl($this, $sermon));
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
        return $this->resolvePreacherAttribute(
            $sermon,
            'name',
            store: 'memoized',
            // Only a non-null loaded profile is authoritative; otherwise fall
            // through to the legacy `preacher` string column.
            fromLoaded: static function (Sermon $sermon): array {
                if ($sermon->preacherProfile === null) {
                    return [false, null];
                }

                return [true, $sermon->preacherProfile->name ?: null];
            },
            fromUnloaded: static function (Sermon $sermon): ?string {
                $preacherName = trim((string) $sermon->preacher);

                return $preacherName === '' ? null : $preacherName;
            },
        );
    }

    /**
     * Resolve a preacher-derived attribute (display name, profile URL, image URL)
     * with the shared identity-keyed memoization the three lookups all need.
     *
     * The skeleton is identical across the three: derive an identity key (profile
     * ID, else the legacy `preacher` string), short-circuit once an authoritative
     * (relation-loaded) result has been computed for that identity, prefer the
     * loaded relation over any previously-cached unloaded fallback, and otherwise
     * cache the fallback. The three callers supply only how the value is computed
     * from a loaded profile versus the unloaded fallback, plus which memo store
     * the result lives in.
     *
     * `$fromLoaded` returns `[true, $value]` when the loaded relation is
     * authoritative for this attribute, or `[false, null]` to skip the loaded
     * branch and fall through to `$fromUnloaded` (e.g. when the profile is null
     * but the attribute still has a string-column fallback).
     *
     * @param  'memoized'|'memoizedUrls'  $store
     * @param  callable(Sermon): array{0: bool, 1: ?string}  $fromLoaded
     * @param  callable(Sermon): ?string  $fromUnloaded
     */
    private function resolvePreacherAttribute(
        Sermon $sermon,
        string $prefix,
        string $store,
        callable $fromLoaded,
        callable $fromUnloaded,
        ?string $valueKey = null,
    ): ?string {
        $identityKey = $sermon->preacher_id !== null
            ? "id_{$sermon->preacher_id}"
            : (string) $sermon->preacher;

        if ($identityKey === '') {
            return null;
        }

        $valueKey ??= $prefix;
        $memoKey = "{$valueKey}_{$identityKey}";
        $authFlag = "{$prefix}_auth_{$identityKey}";
        $computedFlag = "{$prefix}_{$identityKey}";

        if (isset($this->computed[$authFlag])) {
            return $this->{$store}[$memoKey];
        }

        // If the relation is explicitly loaded and authoritative for this
        // attribute, use it as the source of truth and update the memo.
        if ($sermon->relationLoaded('preacherProfile')) {
            [$applies, $value] = $fromLoaded($sermon);

            if ($applies) {
                $this->computed[$authFlag] = true;
                $this->computed[$computedFlag] = true;

                return $this->{$store}[$memoKey] = $value;
            }
        }

        if (isset($this->computed[$computedFlag])) {
            return $this->{$store}[$memoKey];
        }

        // Fall back to the unloaded path and cache the result.
        $this->computed[$computedFlag] = true;

        return $this->{$store}[$memoKey] = $fromUnloaded($sermon);
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
     * Get the descriptive alt text for a sermon thumbnail image.
     */
    public function imageAlt(Sermon $sermon): string
    {
        return $this->metaPresenter->imageAlt($this, $sermon);
    }

    /**
     * Get the descriptive alt text for a Children's Corner talk thumbnail image.
     */
    public function childrensTalkImageAlt(Sermon $sermon): string
    {
        return $this->metaPresenter->childrensTalkImageAlt($this, $sermon);
    }

    /**
     * Generate the SEO meta description for a sermon.
     *
     * The description shape lives on SermonMetaPresenter; this method adds only
     * request-level memoization of the assembled result.
     */
    public function metaDescription(Sermon $sermon): string
    {
        return $this->memoize($sermon, 'meta_desc', 'memoized', fn (): string => $this->metaPresenter->metaDescription($this, $sermon));
    }

    public function videoUrl(Sermon $sermon): ?string
    {
        return $this->memoize($sermon, 'video', 'memoizedUrls', fn (): ?string => $this->urlBuilder->videoUrl($this, $sermon));
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
     * Memoize a per-sermon computation keyed by the sermon's identity + timestamp.
     *
     * Wraps the cacheKey() → isset-check → compute → store dance that every
     * sermon-id-keyed accessor shares. The computed flag is what makes a null
     * result a legitimate cached value (rather than a perpetual cache miss), so
     * the flag is set before the value is stored. `$store` names which memo array
     * the result lives in; the literal-string union keeps the dynamic write
     * type-safe.
     *
     * @template TValue
     *
     * @param  'memoized'|'memoizedUrls'|'memoizedPresents'  $store
     * @param  callable(): TValue  $compute
     * @return TValue
     */
    private function memoize(Sermon $sermon, string $type, string $store, callable $compute): mixed
    {
        $key = $this->cacheKey($sermon, $type);

        if (isset($this->computed[$key])) {
            return $this->{$store}[$key];
        }

        $this->computed[$key] = true;

        return $this->{$store}[$key] = $compute();
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
