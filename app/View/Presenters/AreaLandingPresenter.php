<?php

declare(strict_types=1);

namespace App\View\Presenters;

use App\Models\Page;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AreaLandingPresenter implements PagePresenter
{
    public function __construct(private readonly PageLinksRepository $links) {}

    public function present(Request $request, View $view): array
    {
        $area = $request->segment(1);

        $page = Page::query()
            ->where('slug', $area)
            ->where('area', $area)
            ->first();

        [$description, $heading, $content] = $page
            ? [
                '<meta name="description" content="'.$page->description.'">',
                $page->heading,
                htmlspecialchars_decode($page->body),
            ]
            : [
                '<meta name="description" content="Welcome to our Church.">',
                'Welcome',
                '',
            ];

        return [
            'description' => $description,
            'heading' => $heading,
            'content' => $content,
            'headingpicture' => '/images/headings/large/'.(string) $area.'.webp',
            'area' => $area,
            'slug' => $area,
            'links' => $this->links->randomLinks(
                linkArea: (string) $area,
                slugToExclude: $area,
                secondSlugToExclude: $area,
                excludeAdminPages: true,
                extraExcludedSlugs: ['privacy-policy'],
            ),
        ];
    }
}
