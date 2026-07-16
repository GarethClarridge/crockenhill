<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Public\PageCardService;
use Illuminate\Contracts\View\View;

class LandingPageController extends Controller
{
    public function __construct(
        private readonly PageCardService $pageCardService,
    ) {}

    public function home(): View
    {
        return view('full-width-pages.home', [
            'pages' => $this->pageCardService->forHome(),
        ]);
    }

    public function church(): View
    {
        return view('full-width-pages.church', [
            'pages' => $this->pageCardService->forChurch(),
            'links' => $this->pageCardService->churchLinks(),
        ]);
    }

    public function community(): View
    {
        return view('full-width-pages.community', [
            'pages' => $this->pageCardService->forCommunity(),
        ]);
    }
}
