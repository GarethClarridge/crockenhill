<?php

declare(strict_types=1);

namespace App\View\Components;

use App\Presenters\BreadcrumbPresenter;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class Breadcrumbs extends Component
{
    /** @var list<array{name: string, item: string}> */
    public array $breadcrumbItems;

    /** @var array<string, mixed> */
    public array $breadcrumbList;

    public function __construct(
        BreadcrumbPresenter $presenter,
        public string $area,
        public string $heading,
        public bool $jsonOnly = false,
    ) {
        $this->breadcrumbItems = $presenter->items($area, $heading);
        $this->breadcrumbList = $presenter->jsonLd($this->breadcrumbItems);
    }

    public function render(): View|Closure|string
    {
        return view('components.breadcrumbs');
    }
}
