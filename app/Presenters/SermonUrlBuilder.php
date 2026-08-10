<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Models\Sermon;
use App\Services\Sermon\SermonExposurePolicy;
use App\Services\Sermon\SermonStorageService;

/**
 * Builds the delivery and route URLs for a sermon's media and pages.
 *
 * This collaborator is a pure function of the sermon plus the storage and
 * exposure services.
 */
class SermonUrlBuilder
{
    public function __construct(
        private readonly SermonStorageService $storageService,
        private readonly SermonExposurePolicy $exposurePolicy,
    ) {}

    public function audioUrl(Sermon $sermon): ?string
    {
        return $this->exposurePolicy->isWholeContentPublic($sermon)
            && filled($sermon->audio_file_path)
            ? $this->storageService->getAudioDeliveryUrl($sermon)
            : null;
    }

    public function videoUrl(Sermon $sermon): ?string
    {
        if (! $this->exposurePolicy->shouldExposeVideo($sermon)) {
            return null;
        }

        return $this->storageService->getVideoDeliveryUrl($sermon);
    }

    public function canonicalUrl(Sermon $sermon): string
    {
        return $this->exposurePolicy->canonicalUrl($sermon);
    }

    public function publicUrl(Sermon $sermon): string
    {
        return $this->exposurePolicy->publicUrl($sermon);
    }

    public function thumbnailUrl(Sermon $sermon): ?string
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
    public function cardThumbnailUrl(Sermon $sermon): ?string
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
     * Falls back to the primary thumbnail for listings where the
     * thumbnail_metadata column is not selected.
     */
    public function plainThumbnailUrl(Sermon $sermon): ?string
    {
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
    }

    public function transcriptUrl(Sermon $sermon): ?string
    {
        if (! $this->exposurePolicy->isWholeContentPublic($sermon) || ! $sermon->hasTranscript()) {
            return null;
        }

        return route('sermons.transcript', ['sermon' => $sermon->slug]);
    }
}
