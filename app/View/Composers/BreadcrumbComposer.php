<?php

declare(strict_types=1);

namespace App\View\Composers;

use App\Presenters\BreadcrumbPresenter;
use Illuminate\View\View;

class BreadcrumbComposer
{
    public function __construct(private readonly BreadcrumbPresenter $presenter) {}

    public function compose(View $view): void
    {
        $area = (string) $view->getData()['area'];
        $heading = (string) $view->getData()['heading'];

        $items = $this->presenter->items($area, $heading);

        $view->with([
            'breadcrumbItems' => $items,
            'breadcrumbList' => $this->presenter->jsonLd($items),
        ]);
    }
}
