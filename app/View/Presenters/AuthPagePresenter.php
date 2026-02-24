<?php

declare(strict_types=1);

namespace App\View\Presenters;

use Illuminate\Http\Request;
use Illuminate\View\View;

class AuthPagePresenter implements PagePresenter
{
    public function __construct(private readonly PageLinksRepository $links) {}

    public function present(Request $request, View $view): array
    {
        $area = 'Members';
        $slug = $request->segment(2) ?? $request->segment(1);
        $heading = ucwords(str_replace('-', ' ', (string) $slug));

        return [
            'description' => '<meta name="description" content="'.$heading.'">',
            'heading' => $heading,
            'content' => '',
            'headingpicture' => '/images/headings/large/'.$area.'.webp',
            'area' => $area,
            'slug' => $slug,
            'links' => $this->links->randomLinks(
                linkArea: $area,
                slugToExclude: $area,
                secondSlugToExclude: $slug,
                extraExcludedSlugs: ['homepage'],
            ),
        ];
    }
}
