<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Models\Meeting;
use Spatie\Sitemap\Tags\Url;

class MeetingSitemapPresenter
{
    /**
     * Convert a meeting to a sitemap tag.
     *
     * @return Url|string|array<string, mixed>
     */
    public function toSitemapTag(Meeting $meeting): Url|string|array
    {
        $url = Url::create("/community/{$meeting->slug}")
            ->setChangeFrequency(Url::CHANGE_FREQUENCY_MONTHLY)
            ->setPriority(0.6);

        if ($meeting->updated_at && $meeting->updated_at->year > 0) {
            $url->setLastModificationDate($meeting->updated_at);
        }

        $photo = $meeting->photos->first();
        if ($photo) {
            $url->addImage($photo['url'], $meeting->heading);
        }

        return $url;
    }
}
