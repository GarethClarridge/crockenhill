<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Models\Meeting;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MeetingShowPresenter
{
    /**
     * @return Collection<int, array{name: string, thumbnail: string, url: string}>
     */
    public function photos(Meeting $meeting): Collection
    {
        $media = $meeting->getMedia('photos');

        return Collection::make($media->all())->map(fn (Media $item): array => [
            'name' => $item->name,
            'thumbnail' => $item->getUrl('thumbnail'),
            'url' => $item->getUrl('gallery'),
        ])->values();
    }
}
