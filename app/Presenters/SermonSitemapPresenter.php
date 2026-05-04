<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Models\Sermon;
use Carbon\CarbonInterface;
use Spatie\Sitemap\Tags\Url;

class SermonSitemapPresenter
{
    public function __construct(
        private readonly SermonViewPresenter $sermonViewPresenter,
    ) {}

    /**
     * Convert a sermon to a sitemap tag.
     *
     * Performance Optimization: Replaces expensive Carbon diffInDays call with
     * simple timestamp math to determine priority and change frequency.
     */
    public function toSitemapTag(Sermon $sermon, ?CarbonInterface $now = null): Url
    {
        $nowTimestamp = $now?->getTimestamp() ?? time();
        $sermonTimestamp = $sermon->date->getTimestamp();

        $secondsOld = abs($nowTimestamp - $sermonTimestamp);
        $daysOld = (int) floor($secondsOld / 86400);

        $priority = $daysOld < 30 ? 0.8 : 0.6;
        $changeFreq = $daysOld < 365 ? Url::CHANGE_FREQUENCY_MONTHLY : Url::CHANGE_FREQUENCY_YEARLY;

        // Use updated_at if valid, otherwise fall back to date.
        // Note: old records may have invalid updated_at values (0000-00-00) that aren't null.
        $lastModified = ($sermon->updated_at instanceof CarbonInterface && $sermon->updated_at->year > 0)
            ? $sermon->updated_at
            : $sermon->date;

        $url = Url::create($this->sermonViewPresenter->canonicalUrl($sermon))
            ->setLastModificationDate($lastModified)
            ->setChangeFrequency($changeFreq)
            ->setPriority($priority);

        /**
         * Performance Optimization: Defer media URL resolutions until existence is confirmed via database columns.
         * This avoids redundant Storage::disk() and version hash calls for sermons without media.
         */
        $thumbnailUrl = null;
        if ($sermon->hasThumbnail()) {
            $thumbnailUrl = $this->sermonViewPresenter->thumbnailUrl($sermon);
        }

        $videoUrl = $this->sermonViewPresenter->videoUrl($sermon);

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
