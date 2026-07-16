<?php

declare(strict_types=1);

namespace App\View\Components\Layout;

use App\Models\Page;
use App\Models\User;
use App\Services\Sermon\SermonExposurePolicy;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\Component;

class Header extends Component
{
    public ?User $user;

    /** @var Collection<int, Page> */
    public Collection $pages;

    public bool $canAccessChildrensCorner;

    /**
     * The nav_pages fetch is the one sanctioned shell-component exception to the props convention.
     */
    public function __construct(SermonExposurePolicy $exposurePolicy)
    {
        /** @var ?User $user */
        $user = Auth::user();

        $this->user = $user;
        $this->pages = Cache::flexible(
            'nav_pages',
            [300, 86400],
            fn (): Collection => Page::query()->isNavigation()
                ->public()
                ->select(['id', 'slug', 'heading', 'area'])
                ->get(),
        );
        $this->canAccessChildrensCorner = $exposurePolicy->canAccessChildrensCorner($user);
    }

    public function render(): View|Closure|string
    {
        return view('components.layout.header');
    }
}
