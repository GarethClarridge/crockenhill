<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\Page;
use Illuminate\Support\Collection;

final readonly class PublicPageReadModel
{
    public function __construct(
        public string $area,
        public string $content,
        public string $description,
        public string $heading,
        public ?string $headingpicture,
        public ?string $headingpictureMobile,
        public ?string $headingpictureTablet,
        public string $metaDescription,
        public string $slug,
    ) {}

    /**
     * @param  Collection<int, array{area: string, description: string|null, heading: string, image_url: string, slug: string, url: string}>|array<int, array{area: string, description: string|null, heading: string, image_url: string, slug: string, url: string}>  $links
     * @return array{
     *     area: string,
     *     content: string,
     *     description: string,
     *     heading: string,
     *     headingpicture: ?string,
     *     headingpictureMobile: ?string,
     *     headingpictureTablet: ?string,
     *     links: Collection<int, array{area: string, description: string|null, heading: string, image_url: string, slug: string, url: string}>,
     *     metaDescription: string,
     *     page: ?Page,
     *     slug: string
     * }
     */
    public function toViewData(Collection|array $links = [], ?Page $page = null): array
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
            'metaDescription' => $this->metaDescription,
            'page' => $page,
            'slug' => $this->slug,
        ];
    }
}
