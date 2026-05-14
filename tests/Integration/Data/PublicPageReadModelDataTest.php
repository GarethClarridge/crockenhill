<?php

declare(strict_types=1);

namespace Tests\Integration\Data;

use App\Data\PublicPageReadModel;
use App\Models\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublicPageReadModelDataTest extends TestCase
{
    use RefreshDatabase;

    private function makeModel(): PublicPageReadModel
    {
        return new PublicPageReadModel(
            area: 'church',
            content: '<p>Some content</p>',
            description: 'Short description',
            heading: 'About Us',
            headingpicture: 'images/about.jpg',
            headingpictureMobile: 'images/about-mobile.jpg',
            headingpictureTablet: null,
            metaDescription: 'Meta description for SEO',
            slug: 'about',
        );
    }

    // ── Construction ──────────────────────────────────────────────────────────

    #[Test]
    public function it_stores_all_properties(): void
    {
        $model = $this->makeModel();

        $this->assertSame('church', $model->area);
        $this->assertSame('<p>Some content</p>', $model->content);
        $this->assertSame('Short description', $model->description);
        $this->assertSame('About Us', $model->heading);
        $this->assertSame('images/about.jpg', $model->headingpicture);
        $this->assertSame('images/about-mobile.jpg', $model->headingpictureMobile);
        $this->assertNull($model->headingpictureTablet);
        $this->assertSame('Meta description for SEO', $model->metaDescription);
        $this->assertSame('about', $model->slug);
    }

    // ── toViewData ────────────────────────────────────────────────────────────

    #[Test]
    public function to_view_data_returns_all_keys(): void
    {
        $viewData = $this->makeModel()->toViewData();

        $this->assertArrayHasKey('area', $viewData);
        $this->assertArrayHasKey('content', $viewData);
        $this->assertArrayHasKey('description', $viewData);
        $this->assertArrayHasKey('heading', $viewData);
        $this->assertArrayHasKey('headingpicture', $viewData);
        $this->assertArrayHasKey('headingpictureMobile', $viewData);
        $this->assertArrayHasKey('headingpictureTablet', $viewData);
        $this->assertArrayHasKey('links', $viewData);
        $this->assertArrayHasKey('metaDescription', $viewData);
        $this->assertArrayHasKey('page', $viewData);
        $this->assertArrayHasKey('slug', $viewData);
    }

    #[Test]
    public function to_view_data_defaults_page_to_null(): void
    {
        $viewData = $this->makeModel()->toViewData();

        $this->assertNull($viewData['page']);
    }

    #[Test]
    public function to_view_data_includes_page_model_when_provided(): void
    {
        $page = Page::factory()->create(['slug' => 'about']);

        $viewData = $this->makeModel()->toViewData(page: $page);

        $this->assertInstanceOf(Page::class, $viewData['page']);
    }

    #[Test]
    public function to_view_data_wraps_array_links_in_collection(): void
    {
        $links = [
            ['area' => 'church', 'description' => 'About', 'heading' => 'About Us', 'image_url' => '', 'slug' => 'about', 'url' => '/church/about'],
        ];

        $viewData = $this->makeModel()->toViewData(links: $links);

        $this->assertInstanceOf(Collection::class, $viewData['links']);
        $this->assertCount(1, $viewData['links']);
    }

    #[Test]
    public function to_view_data_passes_through_collection_links_unchanged(): void
    {
        $links = collect([
            ['area' => 'church', 'description' => 'About', 'heading' => 'About', 'image_url' => '', 'slug' => 'about', 'url' => '/about'],
        ]);

        $viewData = $this->makeModel()->toViewData(links: $links);

        $this->assertSame($links, $viewData['links']);
    }

    #[Test]
    public function to_view_data_defaults_links_to_empty_collection(): void
    {
        $viewData = $this->makeModel()->toViewData();

        $this->assertInstanceOf(Collection::class, $viewData['links']);
        $this->assertTrue($viewData['links']->isEmpty());
    }
}
