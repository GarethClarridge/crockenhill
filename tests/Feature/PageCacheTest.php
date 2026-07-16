<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Public\PageListCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PageCacheTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    #[Test]
    public function page_links_are_cached_during_the_fresh_window(): void
    {
        Cache::flush();
        DB::enableQueryLog();

        $repository = app(PageListCache::class);
        $repository->getAllLinksForArea('church');
        $initialQueryCount = count(DB::getQueryLog());

        $repository->getAllLinksForArea('church');

        $this->assertGreaterThan(0, $initialQueryCount);
        $this->assertCount($initialQueryCount, DB::getQueryLog());
    }
}
