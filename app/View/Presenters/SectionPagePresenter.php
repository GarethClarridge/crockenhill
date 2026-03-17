<?php

declare(strict_types=1);

namespace App\View\Presenters;

use App\Models\Page;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SectionPagePresenter implements PagePresenter
{
    public function __construct(private readonly PageLinksRepository $links) {}

    public function present(Request $request, View $view): array
    {
        $area = $request->segment(1);
        $slug = $request->segment(2);

        $page = Page::query()
            ->where('slug', $slug)
            ->where('area', $area)
            ->first();

        [$description, $heading, $content, $headingpicture] = $page
            ? [
                $page->meta_description,
                $page->heading,
                htmlspecialchars_decode($page->body),
                $page->heading_image_url ?? '/images/headings/large/'.(string) $slug.'.webp',
            ]
            : [
                null,
                null,
                '',
                '/images/headings/large/'.(string) $slug.'.webp',
            ];

        return [
            'description' => $description,
            'heading' => $heading,
            'content' => $content,
            'headingpicture' => $headingpicture,
            'area' => $area,
            'slug' => $slug,
            'links' => $this->resolveLinks($request, $slug, $area),
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, Page>
     */
    private function resolveLinks(Request $request, ?string $slug, ?string $area): \Illuminate\Support\Collection
    {
        if ($request->segment(2) === 'sermons') {
            return $this->links->orderedLinks(
                linkArea: 'sermons',
                slugToExclude: $slug,
                secondSlugToExclude: $area,
                excludeAdminPages: true,
            );
        }

        if ($request->segment(2) === 'members') {
            return $this->links->orderedLinks(
                linkArea: 'members',
                slugToExclude: $slug,
                secondSlugToExclude: $area,
                excludeAdminPages: true,
            );
        }

        if ($request->segment(1) === 'community') {
            return $this->links->orderedLinks(
                linkArea: (string) $area,
                slugToExclude: $slug,
                secondSlugToExclude: $area,
                excludeAdminPages: true,
            );
        }

        return $this->links->orderedLinks(
            linkArea: (string) $area,
            slugToExclude: $slug,
            secondSlugToExclude: $area,
            excludeAdminPages: true,
            extraExcludedSlugs: ['privacy-policy', 'attending-in-person', 'resources'],
        );
    }
}
