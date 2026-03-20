<?php

declare(strict_types=1);

namespace App\View\Composers;

use Illuminate\Support\Collection;
use Illuminate\View\View;

class LayoutPageComposer
{
    public function compose(View $view): void
    {
        $view->with([
            'headingpicture' => $view->getData()['headingpicture'] ?? null,
            'headingpictureMobile' => $view->getData()['headingpictureMobile'] ?? null,
            'headingpictureTablet' => $view->getData()['headingpictureTablet'] ?? null,
            'links' => $view->getData()['links'] ?? new Collection,
        ]);
    }
}
