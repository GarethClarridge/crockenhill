<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Models\Sermon;
use App\Services\Sermon\SermonExposurePolicy;
use App\Services\Sermon\SermonStorageService;

/**
 * Builds the delivery and route URLs for a sermon's media and pages.
 *
 * Extracted from SermonViewPresenter so the URL/thumbnail cluster lives behind a
 * single-responsibility collaborator. Following the SermonPresentationAssembler
 * pattern, each method receives the presenter so cross-cutting reads (e.g. the
 * plain-thumbnail fallback to the primary thumbnail) flow back through the
 * presenter's single memoization layer; this collaborator adds no caching of its
 * own and is a pure function of the sermon plus the storage and exposure services.
 */
class SermonUrlBuilder
{
    public function __construct(
        private readonly SermonStorageService $storageService,
        private readonly SermonExposurePolicy $exposurePolicy,
    ) {}

    public function audioUrl(SermonViewPresenter $presenter, Sermon $sermon): ?string
    {
        return filled($sermon->audio_file_path)
            ? $this->storageService->getAudioDeliveryUrl($sermon)
            : null;
    }

    public function videoUrl(SermonViewPresenter $presenter, Sermon $sermon): ?string
    {
        if (! $this->exposurePolicy->shouldExposeVideo($sermon)) {
            return null;
        }

        return $this->storageService->getVideoDeliveryUrl($sermon);
    }

    public function canonicalUrl(SermonViewPresenter $presenter, Sermon $sermon): string
    {
        return $this->exposurePolicy->canonicalUrl($sermon);
    }

    public function publicUrl(SermonViewPresenter $presenter, Sermon $sermon): string
    {
        return $this->exposurePolicy->publicUrl($sermon);
    }

    public function thumbnailUrl(SermonViewPresenter $presenter, Sermon $sermon): ?string
    {
        if (! $this->exposurePolicy->shouldExposeThumbnail($sermon)) {
            return null;
        }

        if (! $sermon->hasThumbnail()) {
            return null;
        }

        return $this->storageService->getThumbnailDeliveryUrl($sermon);
    }

    /**
     * Get the card variant thumbnail URL for a sermon.
     *
     * Performance Optimization: Returns null when the thumbnail_metadata column
     * is not loaded (e.g. in listings) to avoid N+1 queries for large JSON metadata.
     */
    public function cardThumbnailUrl(SermonViewPresenter $presenter, Sermon $sermon): ?string
    {
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
    }

    /**
     * Get the plain variant thumbnail URL for a sermon.
     *
     * Performance Optimization: Falls back to the primary thumbnail (resolved
     * through the presenter, so its memoized result is reused) for listings where
     * the thumbnail_metadata column is not selected.
     */
    public function plainThumbnailUrl(SermonViewPresenter $presenter, Sermon $sermon): ?string
    {
        if (! $this->exposurePolicy->shouldExposeThumbnail($sermon)) {
            return null;
        }

        // Fallback for listings where metadata is not selected: use primary thumbnail
        if (! isset($sermon->getAttributes()['thumbnail_metadata']) && $sermon->hasThumbnail()) {
            return $presenter->thumbnailUrl($sermon);
        }

        if (! $sermon->hasPlainThumbnail()) {
            return null;
        }

        return $this->storageService->getPlainThumbnailDeliveryUrl($sermon);
    }

    public function transcriptUrl(SermonViewPresenter $presenter, Sermon $sermon): ?string
    {
        if (! $sermon->hasTranscript()) {
            return null;
        }

        return route('sermons.transcript', ['sermon' => $sermon->slug]);
    }
}
