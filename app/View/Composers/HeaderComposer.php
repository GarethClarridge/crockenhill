<?php

declare(strict_types=1);

namespace App\View\Composers;

use App\Models\Page;
use App\Services\SermonExposurePolicy;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class HeaderComposer
{
    public function __construct(private readonly SermonExposurePolicy $exposurePolicy) {}

    public function compose(View $view): void
    {
        $user = Auth::user();

        $view->with([
            'user' => $user,
            'pages' => Cache::rememberForever('nav_pages', fn () => Page::isNavigation()
                ->public()
                ->select(['id', 'slug', 'heading', 'area'])
                ->get()),
            'canAccessChildrensCorner' => $this->exposurePolicy->canAccessChildrensCorner($user),
        ]);
    }
}
