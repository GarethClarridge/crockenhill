<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Models\Sermon;
use App\Services\Media\Audio\SermonTranscriptReader;
use App\Services\Sermon\SermonExposurePolicy;
use App\Services\Sermon\SermonStorageService;
use App\Support\SermonContentFormatter;
use Illuminate\Support\Collection;

class SermonViewPresenter
{
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
        private readonly SermonIdentityResolver $identityResolver = new SermonIdentityResolver,
        private readonly SermonPresenterCache $cache = new SermonPresenterCache,
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
        $this->cache->clear();
        $this->dateFormatter->clearCache();
        $this->identityResolver->clearCache();
    }

    public function audioUrl(Sermon $sermon): ?string
    {
        return $this->cache->remember($sermon, 'audio', 'memoizedUrls', fn (): ?string => $this->urlBuilder->audioUrl($this, $sermon));
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
     */
    public function preacherImageUrl(Sermon $sermon): ?string
    {
        return $this->identityResolver->preacherImageUrl($sermon);
    }

    public function canonicalUrl(Sermon $sermon): string
    {
        return $this->cache->remember($sermon, 'canonical', 'memoizedUrls', fn (): string => $this->urlBuilder->canonicalUrl($this, $sermon));
    }

    /**
     * Get the card variant thumbnail URL for a sermon.
     */
    public function cardThumbnailUrl(Sermon $sermon): ?string
    {
        return $this->cache->remember($sermon, 'card_thumb', 'memoizedUrls', fn (): ?string => $this->urlBuilder->cardThumbnailUrl($this, $sermon));
    }

    /**
     * Get the plain variant thumbnail URL for a sermon.
     */
    public function plainThumbnailUrl(Sermon $sermon): ?string
    {
        return $this->cache->remember($sermon, 'plain_thumb', 'memoizedUrls', fn (): ?string => $this->urlBuilder->plainThumbnailUrl($this, $sermon));
    }

    /**
     * Get the preacher's profile URL.
     */
    public function preacherUrl(Sermon $sermon): ?string
    {
        return $this->identityResolver->preacherUrl($sermon);
    }

    /**
     * Get the label for the sermon's service.
     */
    public function serviceLabel(Sermon $sermon): ?string
    {
        return $this->identityResolver->serviceLabel($sermon);
    }

    /**
     * Get the URL for a sermon series.
     */
    public function seriesUrl(Sermon $sermon): ?string
    {
        return $this->identityResolver->seriesUrl($sermon);
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
        return $this->cache->remember($sermon, 'api_present', 'memoizedPresents', fn (): array => $this->assembler->forApi($this, $sermon));
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

        return $this->cache->rememberCollection($collectionKey, fn (): array => $sermons
            ->keyBy('id')
            ->map(fn (Sermon $sermon) => $this->presentForList($sermon))
            ->all());
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
        return $this->cache->remember($sermon, 'list_present', 'memoizedPresents', fn (): array => $this->assembler->forList($this, $sermon));
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
        return $this->cache->remember($sermon, 'full_present', 'memoizedPresents', fn (): array => $this->assembler->forFull($this, $sermon));
    }

    public function publicUrl(Sermon $sermon): string
    {
        return $this->cache->remember($sermon, 'public', 'memoizedUrls', fn (): string => $this->urlBuilder->publicUrl($this, $sermon));
    }

    public function thumbnailUrl(Sermon $sermon): ?string
    {
        return $this->cache->remember($sermon, 'thumb', 'memoizedUrls', fn (): ?string => $this->urlBuilder->thumbnailUrl($this, $sermon));
    }

    public function transcript(Sermon $sermon): ?string
    {
        return $this->transcriptReader->read($sermon);
    }

    public function transcriptUrl(Sermon $sermon): ?string
    {
        return $this->cache->remember($sermon, 'transcript_url', 'memoizedUrls', fn (): ?string => $this->urlBuilder->transcriptUrl($this, $sermon));
    }

    /**
     * Get the preacher name for display.
     */
    public function displayPreacherName(Sermon $sermon): ?string
    {
        return $this->identityResolver->displayPreacherName($sermon);
    }

    /**
     * Get the scripture reference for display.
     */
    public function displayReference(Sermon $sermon): ?string
    {
        return $this->identityResolver->displayReference($sermon);
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
        return $this->cache->remember($sermon, 'meta_desc', 'memoized', fn (): string => $this->metaPresenter->metaDescription($this, $sermon));
    }

    public function videoUrl(Sermon $sermon): ?string
    {
        return $this->cache->remember($sermon, 'video', 'memoizedUrls', fn (): ?string => $this->urlBuilder->videoUrl($this, $sermon));
    }
}
