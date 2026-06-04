<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Public\SitemapService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class SitemapController extends Controller
{
    public function __invoke(SitemapService $sitemapService): Response
    {
        // Laravel 12 flexible cache: fresh for 1 day, stale for 2 days
        Cache::flexible('sitemap', [86400, 172800], fn () => $sitemapService->generate());

        // Regenerate if file is missing (e.g., manually deleted)
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
