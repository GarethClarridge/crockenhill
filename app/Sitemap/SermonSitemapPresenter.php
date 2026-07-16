<?php

declare(strict_types=1);

namespace App\Sitemap;

use App\Models\Sermon;
use App\Presenters\SermonViewPresenter;
use Carbon\CarbonInterface;
use Spatie\Sitemap\Tags\Url;

class SermonSitemapPresenter
{
    /**
     * Performance Optimization: Uses constructor dependency injection for
     * SermonViewPresenter to avoid redundant app() service locator calls
     * during bulk sitemap generation for all sermons.
     */
    public function __construct(
        private readonly SermonViewPresenter $sermonViewPresenter,
    ) {}

    /** Convert a sermon to a sitemap tag. */
    public function toSitemapTag(Sermon $sermon): Url
    {
        // Use updated_at if valid, otherwise fall back to date.
        // Note: old records may have invalid updated_at values (0000-00-00) that aren't null.
        $lastModified = ($sermon->updated_at instanceof CarbonInterface && $sermon->updated_at->year > 0)
            ? $sermon->updated_at
            : $sermon->date;

        $url = Url::create($this->sermonViewPresenter->canonicalUrl($sermon))
            ->setLastModificationDate($lastModified);

        /**
         * Performance Optimization: Defer media URL resolutions until existence is confirmed via database columns.
         * This avoids redundant Storage::disk() and version hash calls for sermons without media.
         */
        $thumbnailUrl = null;
        if ($sermon->hasThumbnail()) {
            $thumbnailUrl = $this->sermonViewPresenter->thumbnailUrl($sermon);
        }

        $videoUrl = $sermon->hasVideo() ? $this->sermonViewPresenter->videoUrl($sermon) : null;

        if ($videoUrl !== null && $thumbnailUrl !== null) {
            $videoOptions = [
                'publication_date' => $sermon->date->toIso8601String(),
            ];
            if ($sermon->duration && $sermon->duration > 0) {
                $videoOptions['duration'] = (int) $sermon->duration;
            }

            $url->addVideo(
                $thumbnailUrl,
                $sermon->title,
                $this->sermonViewPresenter->metaDescription($sermon),
                $videoUrl,
                null,
                $videoOptions
            );
        }

        if ($thumbnailUrl !== null) {
            $url->addImage(
                $thumbnailUrl,
                $this->sermonViewPresenter->metaDescription($sermon),
                '',
                $sermon->title
            );
        }

        return $url;
    }
}
