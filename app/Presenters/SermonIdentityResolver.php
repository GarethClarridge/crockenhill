<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Models\ScripturePassage;
use App\Models\Sermon;
use Illuminate\Support\Str;

/**
 * Resolves a sermon's preacher, scripture-reference, series and service display
 * attributes, memoizing each by the *identity* of the related entity (preacher
 * profile id or legacy name, scripture passage id or legacy reference, series
 * name, service enum value) rather than by the sermon.
 *
 * Extracted from SermonViewPresenter: these lookups share an identity-keyed
 * caching strategy distinct from the presenter's sermon-id-keyed memoize(), so
 * they live together behind a collaborator that owns that cache. The presenter
 * delegates to it and resets it from clearInternalCaches(). The collaborator is
 * self-contained — it reads only the sermon and its loaded relations — so unlike
 * the meta/URL collaborators it does not need the presenter passed back in.
 */
class SermonIdentityResolver
{
    /**
     * General memoization for scalar and nullable values, keyed by entity identity.
     *
     * @var array<string, mixed>
     */
    private array $memoized = [];

    /**
     * Memoization for generated URLs, keyed by entity identity.
     *
     * @var array<string, ?string>
     */
    private array $memoizedUrls = [];

    /**
     * Tracks which keys have been computed, allowing null to be a legitimate cached result.
     *
     * @var array<string, true>
     */
    private array $computed = [];

    /**
     * Clear the identity-keyed memoization caches.
     */
    public function clearCache(): void
    {
        $this->memoized = [];
        $this->memoizedUrls = [];
        $this->computed = [];
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
}
