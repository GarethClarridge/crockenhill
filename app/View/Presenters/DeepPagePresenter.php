<?php

declare(strict_types=1);

namespace App\View\Presenters;

use Illuminate\Http\Request;
use Illuminate\View\View;

class DeepPagePresenter implements PagePresenter
{
    public function __construct(private readonly PageLinksRepository $links) {}

    public function present(Request $request, View $view): array
    {
        $viewData = $view->getData();
        $area = $request->segment(1);
        $slug = $request->segment(2);

        $description = (isset($viewData['description']) && $viewData['description'] !== '')
            ? $viewData['description']
            : '<meta name="description" content="'.(string) $slug.': '.(string) $request->segment(3).'">';

        $providedHeading = $viewData['heading'] ?? null;
        if (is_string($providedHeading) && $providedHeading !== '') {
            $heading = $providedHeading;
        } else {
            $lastSegment = $request->segment(count($request->segments())) ?? '';
            $heading = str_replace('-', ' ', ucwords($lastSegment));
        }

        return [
            'description' => $description,
            'heading' => $heading,
            'content' => $viewData['content'] ?? '',
            'headingpicture' => $viewData['headingpicture'] ?? '/images/headings/large/'.(string) $slug.'.webp',
            'area' => $area,
            'slug' => $slug,
            'links' => $this->resolveLinks($request, $slug, $area),
        ];
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Page>
     */
    private function resolveLinks(Request $request, ?string $slug, ?string $area): \Illuminate\Database\Eloquent\Collection
    {
        if ($request->segment(2) === 'sermons') {
            return $this->links->orderedLinks(
                linkArea: 'sermons',
                slugToExclude: $slug,
                secondSlugToExclude: $area,
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

        return $this->links->orderedLinks(
            linkArea: (string) $area,
            slugToExclude: $slug,
            secondSlugToExclude: $area,
            excludeAdminPages: true,
            extraExcludedSlugs: ['privacy-policy'],
        );
    }
}
