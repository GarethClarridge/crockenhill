<?php

namespace App\Presenters;

use App\Models\Sermon;
use Spatie\Sitemap\Tags\Url;

class SermonSitemapPresenter
{
    public function __construct(
        private readonly SermonViewPresenter $sermonViewPresenter,
    ) {}

    public function toSitemapTag(Sermon $sermon, ?\Illuminate\Support\Carbon $now = null): Url
    {
        $now ??= now();
        $daysOld = abs($now->diffInDays($sermon->date, false));
        $priority = $daysOld < 30 ? 0.8 : 0.6;
        $changeFreq = $daysOld < 365 ? Url::CHANGE_FREQUENCY_MONTHLY : Url::CHANGE_FREQUENCY_YEARLY;

        // Use updated_at if valid, otherwise fall back to date.
        // Note: old records may have invalid updated_at values (0000-00-00) that aren't null.
        $lastModified = $sermon->date;
        if ($sermon->updated_at instanceof \Carbon\CarbonInterface && $sermon->updated_at->year > 0) {
            $lastModified = $sermon->updated_at;
        }

        $thumbnailUrl = null;

        $url = Url::create($this->sermonViewPresenter->canonicalUrl($sermon))
            ->setLastModificationDate($lastModified)
            ->setChangeFrequency($changeFreq)
            ->setPriority($priority);

        if ($sermon->hasVideo()) {
            $videoUrl = $this->sermonViewPresenter->videoUrl($sermon);
            $thumbnailUrl = $this->sermonViewPresenter->thumbnailUrl($sermon);

            if ($videoUrl !== null && $thumbnailUrl !== null) {
                $videoOptions = [];
                if ($sermon->duration && $sermon->duration > 0) {
                    $videoOptions['duration'] = (int) $sermon->duration;
                }

                $url->addVideo(
                    $thumbnailUrl,
                    $sermon->title,
                    $sermon->summary ?? $sermon->title,
                    $videoUrl,
                    null,
                    $videoOptions
                );
            }
        }

        if ($thumbnailUrl === null && $sermon->hasThumbnail()) {
            $thumbnailUrl = $this->sermonViewPresenter->thumbnailUrl($sermon);
        }

        if ($thumbnailUrl !== null) {
            $url->addImage(
                $thumbnailUrl,
                $sermon->meta_description,
                '',
                $sermon->title
            );
        }

        return $url;
    }
}
