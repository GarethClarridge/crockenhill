<?php

namespace Tests\Feature;

use App\Enums\PageArea;
use App\Models\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

        $this->get('/church/cached-public-page')->assertOk();

        $this->assertTrue(Cache::has($cacheKey));

        $page->update(['heading' => 'Updated heading']);

        $this->assertFalse(Cache::has($cacheKey));
        $this->get('/church/cached-public-page')->assertSee('Updated heading');
    }
}
