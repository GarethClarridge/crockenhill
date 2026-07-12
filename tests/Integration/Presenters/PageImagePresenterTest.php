<?php

declare(strict_types=1);

namespace Tests\Integration\Presenters;

use App\Models\Page;
use App\Presenters\PageImagePresenter;
use App\Services\Public\PageImageCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PageImagePresenterTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function has_image_returns_false_when_no_urls_are_available(): void
    {
        /** @var Page $page */
        $page = Page::factory()->create();

        $cacheService = $this->createStub(PageImageCacheService::class);
        $cacheService->method('get')->willReturn([
            'desktop' => null,
            'tablet' => null,
            'mobile' => null,
            'small' => null,
        ]);

        $presenter = new PageImagePresenter($cacheService);

        $this->assertFalse($presenter->hasImage($page));
    }

    #[Test]
    public function has_image_returns_true_when_any_url_is_available(): void
    {
        /** @var Page $page */
        $page = Page::factory()->create();

        $cacheService = $this->createStub(PageImageCacheService::class);
        $cacheService->method('get')->willReturn([
            'desktop' => 'https://cdn.example.com/pages/heading.webp',
            'tablet' => null,
            'mobile' => null,
            'small' => null,
        ]);

        $presenter = new PageImagePresenter($cacheService);

        $this->assertTrue($presenter->hasImage($page));
    }

    #[Test]
    public function page_model_no_longer_owns_has_image_method(): void
    {
        $this->assertFalse(
            method_exists(Page::class, 'hasImage'),
            'hasImage() must not exist on Page model — it was moved to PageImagePresenter (TD-043).'
        );
    }

    #[Test]
    public function page_model_no_longer_owns_heading_image_srcset_attribute(): void
    {
        $this->assertFalse(
            method_exists(Page::class, 'getHeadingImageSrcsetAttribute'),
            'getHeadingImageSrcsetAttribute() must not exist on Page model — it was moved to PageImagePresenter (TD-043).'
        );
    }
}
