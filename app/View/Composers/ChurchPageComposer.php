<?php

declare(strict_types=1);

namespace App\View\Composers;

use App\Services\Public\PageCardService;
use Illuminate\View\View;

class ChurchPageComposer
{
    public function __construct(
        private readonly PageCardService $pageCardService,
    ) {}

    public function compose(View $view): void
    {
        $view->with([
            'pages' => $this->pageCardService->forChurch(),
            'links' => $this->pageCardService->churchLinks(),
        ]);
    }
}
