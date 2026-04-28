<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\Meeting;
use App\Models\Page;
use Illuminate\Support\Collection;

final readonly class PublicMeetingReadModel
{
    /**
     * @param  Collection<int, array{name: string, thumbnail: string, url: string}>  $photos
     * @param  Collection<int, mixed>  $upcomingEvents
     */
    public function __construct(
        public string $area,
        public string $content,
        public string $description,
        public string $heading,
        public ?string $headingpicture,
        public ?string $headingpictureMobile,
        public ?string $headingpictureTablet,
        public string $metaDescription,
        public ?string $pageDescription,
        public Collection $photos,
        public string $slug,
        public Collection $upcomingEvents,
    ) {}

    /**
     * @param  Collection<int, array{area: string, description: string, heading: string, image_url: string, slug: string, url: string}>|array<int, array{area: string, description: string, heading: string, image_url: string, slug: string, url: string}>  $links
     * @param  Collection<int, mixed>  $pastEvents
     * @return array{
     *     area: string,
     *     content: string,
     *     description: string,
     *     heading: string,
     *     headingpicture: ?string,
     *     headingpictureMobile: ?string,
     *     headingpictureTablet: ?string,
     *     links: Collection<int, array{area: string, description: string, heading: string, image_url: string, slug: string, url: string}>,
     *     meeting: Meeting,
     *     metaDescription: string,
     *     page: ?Page,
     *     pageDescription: ?string,
     *     pastEvents: Collection<int, mixed>,
     *     photos: Collection<int, array{name: string, thumbnail: string, url: string}>,
     *     slug: string,
     *     upcomingEvents: Collection<int, mixed>
     * }
     */
    public function toViewData(Collection|array $links, Meeting $meeting, Collection $pastEvents, ?Page $page = null): array
    {
        return [
            'area' => $this->area,
            'content' => $this->content,
            'description' => $this->description,
            'heading' => $this->heading,
            'headingpicture' => $this->headingpicture,
            'headingpictureMobile' => $this->headingpictureMobile,
            'headingpictureTablet' => $this->headingpictureTablet,
            'links' => $links instanceof Collection ? $links : collect($links),
            'meeting' => $meeting,
            'metaDescription' => $this->metaDescription,
            'page' => $page,
            'pageDescription' => $this->pageDescription,
            'pastEvents' => $pastEvents,
            'photos' => $this->photos,
            'slug' => $this->slug,
            'upcomingEvents' => $this->upcomingEvents,
        ];
    }
}
