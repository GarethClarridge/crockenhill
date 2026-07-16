<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Enums\PageArea;
use App\Models\Page;
use App\Services\Public\PageListCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PageListCacheTest extends TestCase
{
    use RefreshDatabase;

    private PageListCache $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(PageListCache::class);
    }

    #[Test]
    public function get_all_links_for_area_selects_only_required_columns(): void
    {
        $createdPage = Page::factory()->create([
            'area' => PageArea::Community,
            'admin' => 'no',
            'body' => 'Body content that should not be cached.',
            'markdown' => 'Markdown content that should not be cached.',
        ]);

        $page = $this->repository
            ->getAllLinksForArea(PageArea::Community)
            ->firstWhere('id', $createdPage->id);

        $this->assertInstanceOf(Page::class, $page);

        $attributes = $page->getAttributes();

        $this->assertArrayNotHasKey('body', $attributes);
        $this->assertArrayNotHasKey('markdown', $attributes);
    }

    #[Test]
    public function get_all_links_for_area_does_not_eager_load_media_into_cache(): void
    {
        // Spatie's Media model is not on the cache.serializable_classes allow-list,
        // so any cached Eloquent collection that carries a loaded `media` relation
        // hydrates as __PHP_Incomplete_Class on the next read and crashes the
        // downstream filter callback in Spatie's loadMedia(). The repository
        // must therefore avoid attaching Media to the cached page collection.
        Page::factory()->create([
            'area' => PageArea::Community,
            'admin' => 'no',
        ]);

        $links = $this->repository->getAllLinksForArea(PageArea::Community);

        $this->assertNotEmpty($links);
        foreach ($links as $candidate) {
            $this->assertArrayNotHasKey(
                'media',
                $candidate->getRelations(),
                'Cached page collection must not carry a loaded media relation.',
            );
        }
    }

    #[Test]
    public function get_links_for_area_slugs_does_not_eager_load_media_into_cache(): void
    {
        $page = Page::factory()->create([
            'area' => PageArea::Community,
            'admin' => 'no',
        ]);

        $links = $this->repository->getLinksForAreaSlugs(
            PageArea::Community,
            [$page->slug],
            'page_links_test_'.$page->id,
        );

        $this->assertNotEmpty($links);
        foreach ($links as $candidate) {
            $this->assertArrayNotHasKey(
                'media',
                $candidate->getRelations(),
                'Cached page collection must not carry a loaded media relation.',
            );
        }
    }
}
