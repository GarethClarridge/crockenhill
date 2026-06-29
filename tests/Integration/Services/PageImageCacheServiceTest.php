<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Models\Page;
use App\Services\Public\PageImageCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
    public function it_resolves_public_fallback_urls_when_webp_files_exist_in_public_directory(): void
    {
        Storage::fake('public');

        $page = Page::factory()->create(['slug' => 'public-page']);

        // Create the directory in public path
        $dir = public_path('images/headings/large');
        if (! file_exists($dir)) {
            mkdir($dir, 0755, true);
        }

        $filePath = "{$dir}/public-page.webp";
        file_put_contents($filePath, 'fake-image-data');

        try {
            $result = $this->service->get($page);

            $this->assertNotNull($result['desktop']);
            $this->assertStringContainsString('images/headings/large/public-page.webp', $result['desktop']);
        } finally {
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
    }

    // ── Cache behaviour ───────────────────────────────────────────────────────

    #[Test]
    public function it_caches_results_on_first_call(): void
    {
        Storage::fake('public');

        $page = Page::factory()->create(['slug' => 'cached-page']);

        $this->service->get($page);

        $this->assertTrue(Cache::has("public_page_images_{$page->id}"));
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
        $this->service->get($page);

        $this->assertTrue(Cache::has("public_page_images_{$page->id}"));

        $this->service->forget($page);

        $this->assertFalse(Cache::has("public_page_images_{$page->id}"));
    }

    #[Test]
    public function it_clears_the_cache_when_forget_is_called_with_an_integer_id(): void
    {
        Storage::fake('public');

        $page = Page::factory()->create();
        $this->service->get($page);

        $this->assertTrue(Cache::has("public_page_images_{$page->id}"));

        $this->service->forget($page->id);

        $this->assertFalse(Cache::has("public_page_images_{$page->id}"));
    }
}
