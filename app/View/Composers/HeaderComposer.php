<?php

declare(strict_types=1);

namespace App\View\Composers;

use App\Models\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class HeaderComposer
{
    public function compose(View $view): void
    {
        $view->with([
            'user' => Auth::user(),
            'pages' => Cache::rememberForever('nav_pages', fn () => Page::isNavigation()
                ->select(['id', 'slug', 'heading', 'area'])
                ->get()),
        ]);
    }
}
