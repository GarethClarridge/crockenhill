<?php

declare(strict_types=1);

namespace Tests\Unit\Presenters;

use App\Enums\PageArea;
use App\Models\Page;
use App\Presenters\PageCardPresenter;
use App\Presenters\PageImagePresenter;
use Illuminate\Support\Collection;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PageCardPresenterTest extends TestCase
{
    private PageImagePresenter&MockInterface $pageImagePresenter;

    private PageCardPresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pageImagePresenter = Mockery::mock(PageImagePresenter::class);
        $this->presenter = new PageCardPresenter($this->pageImagePresenter);
    }

    #[Test]
    public function it_presents_sermon_page_with_slug_all_and_maps_to_sermons_index(): void
    {
        // Arrange
        $page = Page::factory()->make([
            'area' => PageArea::Sermons,
            'slug' => 'all',
            'description' => 'Test sermon archive description',
            'heading' => 'All Sermons',
        ]);

        $this->pageImagePresenter
            ->shouldReceive('headingImageSmallUrl')
            ->once()
            ->with($page)
            ->andReturn(null);

        // Act
        $result = $this->presenter->present($page);

        // Assert
        $this->assertSame('sermons', $result['area']);
        $this->assertSame('Test sermon archive description', $result['description']);
        $this->assertSame('All Sermons', $result['heading']);
        $this->assertSame('/images/headings/small/default.webp', $result['image_url']);
        $this->assertSame('all', $result['slug']);
        $this->assertSame(route('sermons.index'), $result['url']);
    }

    #[Test]
    public function it_presents_sermon_page_with_other_slug_and_maps_to_specific_sermon_url(): void
    {
        // Arrange
        $page = Page::factory()->make([
            'area' => PageArea::Sermons,
            'slug' => 'morning-service',
            'description' => 'Morning Service description',
            'heading' => 'Morning Service',
        ]);

        $this->pageImagePresenter
            ->shouldReceive('headingImageSmallUrl')
            ->once()
            ->with($page)
            ->andReturn('https://cdn.example.com/custom-image.webp');

        // Act
        $result = $this->presenter->present($page);

        // Assert
        $this->assertSame('sermons', $result['area']);
        $this->assertSame('Morning Service description', $result['description']);
        $this->assertSame('Morning Service', $result['heading']);
        $this->assertSame('https://cdn.example.com/custom-image.webp', $result['image_url']);
        $this->assertSame('morning-service', $result['slug']);
        $this->assertSame('/christ/sermons/morning-service', $result['url']);
    }

    #[Test]
    public function it_presents_other_area_page_with_default_slug_mapping(): void
    {
        // Arrange
        $page = Page::factory()->make([
            'area' => PageArea::Community,
            'slug' => 'bible-study',
            'description' => 'Bible Study description',
            'heading' => 'Bible Study Group',
        ]);

        $this->pageImagePresenter
            ->shouldReceive('headingImageSmallUrl')
            ->once()
            ->with($page)
            ->andReturn(null);

        // Act
        $result = $this->presenter->present($page);

        // Assert
        $this->assertSame('community', $result['area']);
        $this->assertSame('Bible Study description', $result['description']);
        $this->assertSame('Bible Study Group', $result['heading']);
        $this->assertSame('/images/headings/small/default.webp', $result['image_url']);
        $this->assertSame('bible-study', $result['slug']);
        $this->assertSame('/community/bible-study', $result['url']);
    }

    #[Test]
    public function it_presents_collection_of_pages(): void
    {
        // Arrange
        $page1 = Page::factory()->make([
            'area' => PageArea::Church,
            'slug' => 'about-us',
            'description' => 'About Us description',
            'heading' => 'About Us',
        ]);

        $page2 = Page::factory()->make([
            'area' => PageArea::Community,
            'slug' => 'buzz-club',
            'description' => 'Buzz Club description',
            'heading' => 'Buzz Club',
        ]);

        $this->pageImagePresenter
            ->shouldReceive('headingImageSmallUrl')
            ->with($page1)
            ->andReturn('https://cdn.example.com/about.webp');

        $this->pageImagePresenter
            ->shouldReceive('headingImageSmallUrl')
            ->with($page2)
            ->andReturn(null);

        $pages = new Collection([$page1, $page2]);

        // Act
        $results = $this->presenter->presentCollection($pages);

        // Assert
        $this->assertInstanceOf(Collection::class, $results);
        $this->assertCount(2, $results);

        $first = $results->first();
        $this->assertSame('church', $first['area']);
        $this->assertSame('About Us description', $first['description']);
        $this->assertSame('About Us', $first['heading']);
        $this->assertSame('https://cdn.example.com/about.webp', $first['image_url']);
        $this->assertSame('about-us', $first['slug']);
        $this->assertSame('/church/about-us', $first['url']);

        $second = $results->last();
        $this->assertSame('community', $second['area']);
        $this->assertSame('Buzz Club description', $second['description']);
        $this->assertSame('Buzz Club', $second['heading']);
        $this->assertSame('/images/headings/small/default.webp', $second['image_url']);
        $this->assertSame('buzz-club', $second['slug']);
        $this->assertSame('/community/buzz-club', $second['url']);
    }
}
