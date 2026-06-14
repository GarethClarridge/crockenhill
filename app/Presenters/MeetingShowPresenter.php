<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Models\Meeting;
use App\Models\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MeetingShowPresenter
{
    public function __construct(
        private readonly PageLayoutPresenter $pageLayoutPresenter,
    ) {}

    /**
     * @param  Collection<int, array{area: string, description: string|null, heading: string, image_url: string, slug: string, url: string}>  $links
     * @return array{
     *     area: string,
     *     content: string,
     *     description: string|null,
     *     heading: string,
     *     headingpicture: ?string,
     *     headingpictureMobile: ?string,
     *     headingpictureTablet: ?string,
     *     links: Collection<int, array{area: string, description: string|null, heading: string, image_url: string, slug: string, url: string}>,
     *     page: ?Page,
     *     slug: string
     * }
     */
    public function layoutData(Meeting $meeting, Collection $links): array
    {
        if ($meeting->page instanceof Page) {
            return $this->pageLayoutPresenter->present(
                page: $meeting->page,
                area: 'community',
                slug: $meeting->slug,
                links: $links,
            );
        }

        $heading = Str::title(str_replace('-', ' ', $meeting->slug));

        return [
            'area' => 'community',
            'content' => '',
            'description' => $heading,
            'heading' => $heading,
            'headingpicture' => null,
            'headingpictureMobile' => null,
            'headingpictureTablet' => null,
            'links' => $links,
            'page' => null,
            'slug' => $meeting->slug,
        ];
    }

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
