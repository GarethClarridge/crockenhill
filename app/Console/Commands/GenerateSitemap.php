<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SitemapService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class GenerateSitemap extends Command
{
    protected $signature = 'sitemap:generate';

    protected $description = 'Generate the sitemap.xml file';

    public function handle(SitemapService $sitemapService): int
    {
        Cache::forget('sitemap');
        $sitemapService->generate();
        $this->info("Sitemap generated at {$sitemapService->getFilePath()}");

        return 0;
    }
}
