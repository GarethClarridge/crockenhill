<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PageArea;
use App\Models\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublicPageReadModelCacheTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function public_page_read_model_cache_is_populated_and_invalidated_on_page_update(): void
    {
        $page = Page::factory()->create([
            'area' => PageArea::Church,
            'slug' => 'cached-public-page',
            'heading' => 'Original heading',
            'admin' => 'no',
        ]);

        $cacheKey = "public_page_view_{$page->id}";
        Cache::forget($cacheKey);

        DB::enableQueryLog();

        // First request: should hit the database
        $this->get('/church/cached-public-page')->assertOk();
        $queriesAfterFirstCall = count(DB::getQueryLog());
        $this->assertGreaterThan(0, $queriesAfterFirstCall);

        // Second request: should use the read model from cache, but still
        // performs the initial page lookup to verify existence and visibility.
        // We expect only 1 query (the Page::firstOrFail() in PageController).
        $this->get('/church/cached-public-page')->assertOk();
        $this->assertCount($queriesAfterFirstCall + 1, DB::getQueryLog());

        // Update page: should invalidate cache
        $page->update(['heading' => 'Updated heading']);

        // Third request: should hit the database again
        $this->get('/church/cached-public-page')
            ->assertOk()
            ->assertSee('Updated heading');

        $this->assertGreaterThan($queriesAfterFirstCall, count(DB::getQueryLog()));
    }
}
