<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Public\SitemapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('dedicated')]
class SitemapCacheTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        @unlink(app(SitemapService::class)->getFilePath());
        Cache::flush();
    }

    protected function tearDown(): void
    {
        @unlink(app(SitemapService::class)->getFilePath());

        parent::tearDown();
    }

    #[Test]
    public function sitemap_is_generated_once_during_the_fresh_cache_window(): void
    {
        DB::enableQueryLog();

        $this->get('/sitemap.xml')->assertOk();
        $initialQueryCount = count(DB::getQueryLog());

        $this->get('/sitemap.xml')->assertOk();

        $this->assertGreaterThan(0, $initialQueryCount);
        $this->assertCount($initialQueryCount, DB::getQueryLog());
    }
}
