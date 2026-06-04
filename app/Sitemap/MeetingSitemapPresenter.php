<?php

declare(strict_types=1);

namespace App\Sitemap;

use App\Models\Meeting;
use Spatie\Sitemap\Tags\Url;

class MeetingSitemapPresenter
{
    /**
     * Convert a meeting to a sitemap tag.
     *
     * Performance Optimization: Uses getFirstMediaUrl() with the 'gallery' conversion
     * directly to avoid the overhead of the Meeting::photos attribute, which maps
     * the entire collection and generates multiple URLs when only one is needed.
     *
     * @return Url|string|array<string, mixed>
     */
    public function toSitemapTag(Meeting $meeting): Url|string|array
    {
        $url = Url::create(route('meetings.show', ['meeting' => $meeting->slug]))
            ->setChangeFrequency(Url::CHANGE_FREQUENCY_MONTHLY)
            ->setPriority(0.6);

        if ($meeting->updated_at && $meeting->updated_at->year > 0) {
            $url->setLastModificationDate($meeting->updated_at);
        }

        $photoUrl = $meeting->getFirstMediaUrl('photos', 'gallery');
        if ($photoUrl !== '') {
            $url->addImage($photoUrl, $meeting->heading);
        }

        return $url;
    }
}
