<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Public\SitemapService;
use Illuminate\Console\Command;

class GenerateSitemap extends Command
{
    protected $signature = 'sitemap:generate';

    protected $description = 'Generate the sitemap.xml file';

    public function handle(SitemapService $sitemapService): int
    {
        $sitemapService->generate();
        $this->info("Sitemap generated at {$sitemapService->getFilePath()}");

        return 0;
    }
}
