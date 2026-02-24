<?php

declare(strict_types=1);

namespace App\View\Presenters;

use App\Models\Sermon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SermonDetailPresenter implements PagePresenter
{
    public function __construct(private readonly PageLinksRepository $links) {}

    public function present(Request $request, View $view): array
    {
        $slug = $request->segment(5);
        $area = $request->segment(1);

        $sermon = Sermon::query()
            ->where('slug', $slug)
            ->whereMonth('date', '=', (string) $request->segment(4))
            ->whereYear('date', '=', (string) $request->segment(3))
            ->first();

        if (! $sermon) {
            return [
                'description' => '',
                'heading' => '',
                'content' => '',
                'headingpicture' => '',
                'area' => $area,
                'slug' => $slug,
                'links' => new Collection,
            ];
        }

        return [
            'description' => '<meta name="description" content="'.$sermon->title.'">',
            'heading' => $sermon->title,
            'content' => '',
            'headingpicture' => '/images/headings/large/'.(string) $slug.'.webp',
            'area' => $area,
            'slug' => $slug,
            'links' => $this->links->orderedLinks(
                linkArea: (string) $request->segment(2),
                slugToExclude: $slug,
                secondSlugToExclude: $area,
                extraExcludedSlugs: ['homepage'],
            ),
        ];
    }
}
