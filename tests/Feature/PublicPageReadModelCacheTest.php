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

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    #[Test]
    public function public_page_read_model_cache_is_populated_and_invalidated_on_page_update(): void
    {
        $page = Page::factory()->create([
            'area' => PageArea::Church,
            'slug' => 'cached-public-page',
            'heading' => 'Original heading',
            'admin' => 'no',
        ]);

        DB::enableQueryLog();

        // First request: should hit the database
        $this->get('/church/cached-public-page')->assertOk();
        $queriesAfterFirstCall = count(DB::getQueryLog());
        $this->assertGreaterThan(0, $queriesAfterFirstCall);

        // Second request still resolves the route model and shell data, but avoids
        // rebuilding the page read model and image data.
        $this->get('/church/cached-public-page')->assertOk();
        $secondRequestQueries = count(DB::getQueryLog()) - $queriesAfterFirstCall;
        $this->assertLessThan($queriesAfterFirstCall, $secondRequestQueries);

        // Update page: should invalidate cache
        $page->update(['heading' => 'Updated heading']);
        DB::flushQueryLog();

        // Third request: should hit the database again
        $this->get('/church/cached-public-page')
            ->assertOk()
            ->assertSee('Updated heading');

        $this->assertNotEmpty(DB::getQueryLog());
    }
}
