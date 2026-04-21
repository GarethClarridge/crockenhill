<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Models\Preacher;
use Spatie\Sitemap\Tags\Url;

class PreacherSitemapPresenter
{
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

        if ($preacher->profile_image_url) {
            $url->addImage($preacher->profile_image_url, "Preacher: {$preacher->name}");
        }

        return $url;
    }
}
