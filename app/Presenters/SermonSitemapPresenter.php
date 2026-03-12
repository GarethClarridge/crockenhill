<?php

namespace App\Presenters;

use App\Models\Sermon;
use App\Services\SermonExposurePolicy;
use Carbon\Carbon;
use Spatie\Sitemap\Tags\Url;

class SermonSitemapPresenter
{
    private readonly SermonExposurePolicy $exposurePolicy;

    public function __construct(
        ?SermonExposurePolicy $exposurePolicy = null,
    ) {
        $this->exposurePolicy = $exposurePolicy ?? app(SermonExposurePolicy::class);
    }

    public function toSitemapTag(Sermon $sermon): Url
    {
        $daysOld = abs(now()->diffInDays($sermon->date, false));
        $priority = $daysOld < 30 ? 0.8 : 0.6;
        $changeFreq = $daysOld < 365 ? Url::CHANGE_FREQUENCY_MONTHLY : Url::CHANGE_FREQUENCY_YEARLY;

        // Use updated_at if valid, otherwise fall back to date.
        // Note: old records may have invalid updated_at values (0000-00-00) that aren't null.
        // Also, timestamps are disabled so updated_at returns as string, not Carbon.
        $lastModified = $sermon->date;
        if ($sermon->updated_at) {
            $updatedAt = Carbon::parse($sermon->updated_at);
            if ($updatedAt->year > 0) {
                $lastModified = $updatedAt;
            }
        }

        $url = Url::create($this->exposurePolicy->canonicalUrl($sermon))
            ->setLastModificationDate($lastModified)
            ->setChangeFrequency($changeFreq)
            ->setPriority($priority);

        if ($sermon->hasVideo() && $sermon->video_url) {
            $thumbnailUrl = $sermon->thumbnail_url;
            if ($thumbnailUrl) {
                $videoOptions = [];
                if ($sermon->duration && $sermon->duration > 0) {
                    $videoOptions['duration'] = (int) $sermon->duration;
                }
                $url->addVideo(
                    $thumbnailUrl,
                    $sermon->title,
                    $sermon->summary ?? $sermon->title,
                    $sermon->video_url,
                    null,
                    $videoOptions
                );
            }
        }

        if ($sermon->hasThumbnail() && $sermon->thumbnail_url) {
            $url->addImage(
                $sermon->thumbnail_url,
                $sermon->meta_description,
                '',
                $sermon->title
            );
        }

        return $url;
    }
}
