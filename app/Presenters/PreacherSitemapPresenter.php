<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Models\Preacher;
use Spatie\Sitemap\Tags\Url;

class PreacherSitemapPresenter
{
    public function __construct(
        private readonly SermonViewPresenter $sermonViewPresenter,
    ) {}

    /**
     * Convert a preacher to a sitemap tag.
     *
     * @return Url|string|array<string, mixed>
     */
    public function toSitemapTag(Preacher $preacher): Url|string|array
    {
        $url = Url::create("/christ/sermons/preachers/{$preacher->slug}")
            ->setChangeFrequency(Url::CHANGE_FREQUENCY_MONTHLY)
            ->setPriority(0.6);

        if ($preacher->updated_at && $preacher->updated_at->year > 0) {
            $url->setLastModificationDate($preacher->updated_at);
        }

        $imageUrl = $preacher->profile_image_url;

        if (! $imageUrl && $preacher->relationLoaded('sermons')) {
            $latestSermon = $preacher->sermons->first();
            if ($latestSermon) {
                $imageUrl = $this->sermonViewPresenter->thumbnailUrl($latestSermon);
            }
        }

        if ($imageUrl) {
            $url->addImage($imageUrl, "Preacher: {$preacher->name}");
        }

        return $url;
    }
}
