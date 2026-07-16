<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Public\SitemapService;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function __invoke(SitemapService $sitemapService): Response
    {
        if ($sitemapService->shouldRegenerate()) {
            $sitemapService->generate();
        }

        $content = file_get_contents($sitemapService->getFilePath());

        if ($content === false) {
            abort(500, 'Sitemap file could not be read.');
        }

        return response($content, 200, [
            'Content-Type' => 'application/xml',
        ]);
    }
}
