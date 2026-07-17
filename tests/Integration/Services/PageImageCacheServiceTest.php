<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Models\Page;
use App\Services\Public\PageImageCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PageImageCacheServiceTest extends TestCase
{
    use RefreshDatabase;

    private PageImageCacheService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PageImageCacheService::class);
    }

    // ── Happy path ────────────────────────────────────────────────────────────

    #[Test]
    public function it_returns_the_four_expected_image_size_keys(): void
    {
        Storage::fake('public');

        $page = Page::factory()->create();

        $result = $this->service->get($page);

        $this->assertArrayHasKey('desktop', $result);
        $this->assertArrayHasKey('mobile', $result);
        $this->assertArrayHasKey('small', $result);
        $this->assertArrayHasKey('tablet', $result);
    }

    #[Test]
    public function it_returns_null_for_all_sizes_when_no_media_and_no_storage_files_exist(): void
    {
        Storage::fake('public');

        $page = Page::factory()->create(['slug' => 'test-page']);

        $result = $this->service->get($page);

        $this->assertNull($result['desktop']);
        $this->assertNull($result['mobile']);
        $this->assertNull($result['small']);
        $this->assertNull($result['tablet']);
    }

    #[Test]
    public function it_resolves_storage_fallback_urls_when_webp_files_exist(): void
    {
        Storage::fake('public');

        $page = Page::factory()->create(['slug' => 'our-church']);

        // Place webp files at the expected storage paths
        Storage::disk('public')->put('pages/headings/large/our-church.webp', 'fake-image-data');
        Storage::disk('public')->put('pages/headings/small/our-church.webp', 'fake-image-data');

        $result = $this->service->get($page);

        // desktop and tablet use the 'large' size path
        $this->assertNotNull($result['desktop']);
        $this->assertStringContainsString('our-church.webp', $result['desktop']);

        // mobile and small use the 'small' size path
        $this->assertNotNull($result['mobile']);
        $this->assertStringContainsString('our-church.webp', $result['mobile']);
    }

    #[Test]
    public function it_falls_back_to_the_original_url_for_media_with_only_legacy_conversions(): void
    {
        Storage::fake('public');

        $page = Page::factory()->create(['slug' => 'legacy-media-page']);

        $directory = storage_path('framework/testing');
        File::ensureDirectoryExists($directory);
        $filePath = "{$directory}/legacy-heading.png";
        File::put($filePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

        $media = $page->addMedia($filePath)->toMediaCollection('headings');

        // Simulate a row generated when only the legacy conversions were registered
        $media->update(['generated_conversions' => ['large' => true, 'small' => true]]);

        $result = $this->service->get($page->fresh());

        $this->assertStringContainsString('legacy-heading', (string) $result['desktop']);
        $this->assertStringContainsString('legacy-heading', (string) $result['mobile']);
        $this->assertStringContainsString('legacy-heading', (string) $result['small']);
        $this->assertStringContainsString('legacy-heading', (string) $result['tablet']);
    }

    // ── Cache behaviour ───────────────────────────────────────────────────────

    #[Test]
    public function it_caches_results_on_first_call(): void
    {
        Storage::fake('public');

        $page = Page::factory()->create(['slug' => 'cached-page']);

        // First call populates cache
        $this->service->get($page);

        // Second call should hit cache and trigger zero queries.
        // We fetch a fresh instance *before* enabling the query log to ensure
        // the select query for the page model itself isn't counted, while still
        // bypassing memoized relationships on the original $page instance.
        $freshPage = $page->fresh();
        DB::enableQueryLog();
        $this->service->get($freshPage);
        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
    }

    #[Test]
    public function it_returns_cached_result_on_subsequent_calls(): void
    {
        Storage::fake('public');

        $page = Page::factory()->create(['slug' => 'repeated-page']);

        $firstResult = $this->service->get($page);
        $secondResult = $this->service->get($page);

        $this->assertSame($firstResult, $secondResult);
    }

    #[Test]
    public function it_clears_the_cache_when_forget_is_called_with_a_page_model(): void
    {
        Storage::fake('public');

        $page = Page::factory()->create();

        // Populate cache
        $this->service->get($page);

        $this->service->forget($page);

        // Subsequent call should trigger a query because cache was cleared.
        // We use fresh() *before* enabling the query log to bypass memoization
        // on the original instance without counting the fresh fetch query.
        $freshPage = $page->fresh();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->service->get($freshPage);
        $this->assertNotCount(0, DB::getQueryLog());
        DB::disableQueryLog();
    }

    #[Test]
    public function it_clears_the_cache_when_forget_is_called_with_an_integer_id(): void
    {
        Storage::fake('public');

        $page = Page::factory()->create();

        // Populate cache
        $this->service->get($page);

        $this->service->forget($page->id);

        // Subsequent call should trigger a query because cache was cleared.
        // We use fresh() *before* enabling the query log to bypass memoization
        // on the original instance without counting the fresh fetch query.
        $freshPage = $page->fresh();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->service->get($freshPage);
        $this->assertNotCount(0, DB::getQueryLog());
        DB::disableQueryLog();
    }
}
