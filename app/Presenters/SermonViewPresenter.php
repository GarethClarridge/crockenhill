<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Models\Sermon;
use App\Services\SermonExposurePolicy;
use App\Services\SermonStorageService;
use App\Services\SermonTranscriptReader;
use Illuminate\Support\Str;

class SermonViewPresenter
{
    public function __construct(
        private readonly SermonExposurePolicy $exposurePolicy,
        private readonly SermonStorageService $storageService,
        private readonly SermonTranscriptReader $transcriptReader,
    ) {}

    public function audioUrl(Sermon $sermon): ?string
    {
        if (! filled($sermon->audio_file_path)) {
            return null;
        }

        return $this->storageService->getPublicUrl($sermon);
    }

    public function canonicalUrl(Sermon $sermon): string
    {
        return $this->exposurePolicy->canonicalUrl($sermon);
    }

    public function cardThumbnailUrl(Sermon $sermon): ?string
    {
        if (! $sermon->hasPlainThumbnail()) {
            return null;
        }

        return $this->storageService->getCardThumbnailUrl($sermon);
    }

    public function preacherUrl(Sermon $sermon): ?string
    {
        if ($sermon->relationLoaded('preacherProfile') && $sermon->preacherProfile !== null) {
            return '/christ/sermons/preachers/'.$sermon->preacherProfile->slug;
        }

        return filled($sermon->preacher)
            ? '/christ/sermons/preachers/'.Str::slug($sermon->preacher)
            : null;
    }

    /**
     * @return array{
     *     audio_url: ?string,
     *     canonical_url: string,
     *     card_thumbnail_url: ?string,
     *     preacher_url: ?string,
     *     public_url: string,
     *     thumbnail_url: ?string,
     *     transcript: ?string,
     *     video_url: ?string
     * }
     */
    public function present(Sermon $sermon): array
    {
        return [
            'audio_url' => $this->audioUrl($sermon),
            'canonical_url' => $this->canonicalUrl($sermon),
            'card_thumbnail_url' => $this->cardThumbnailUrl($sermon),
            'preacher_url' => $this->preacherUrl($sermon),
            'public_url' => $this->publicUrl($sermon),
            'thumbnail_url' => $this->thumbnailUrl($sermon),
            'transcript' => $this->transcriptReader->read($sermon),
            'video_url' => $this->videoUrl($sermon),
        ];
    }

    public function publicUrl(Sermon $sermon): string
    {
        return $this->exposurePolicy->publicUrl($sermon);
    }

    public function thumbnailUrl(Sermon $sermon): ?string
    {
        if (! $sermon->hasThumbnail()) {
            return null;
        }

        return $this->storageService->getThumbnailUrl($sermon);
    }

    public function transcript(Sermon $sermon): ?string
    {
        return $this->transcriptReader->read($sermon);
    }

    public function videoUrl(Sermon $sermon): ?string
    {
        if (! $sermon->hasVideo()) {
            return null;
        }

        return $this->storageService->getVideoUrl($sermon);
    }
}
