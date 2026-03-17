<?php

declare(strict_types=1);

namespace Tests\Unit\View\Presenters;

use App\Enums\PageArea;
use App\Models\Page;
use App\View\Presenters\PageLinksRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PageLinksRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private PageLinksRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = app(PageLinksRepository::class);
    }

    #[Test]
    public function it_returns_ordered_links_for_an_area(): void
    {
        Page::factory()->create(['area' => PageArea::CHURCH, 'slug' => 'beta', 'admin' => 'no']);
        Page::factory()->create(['area' => PageArea::CHURCH, 'slug' => 'alpha', 'admin' => 'no']);
        Page::factory()->create(['area' => PageArea::COMMUNITY, 'slug' => 'gamma', 'admin' => 'no']);

        $results = $this->repository->orderedLinks('church', null, null);

        $this->assertCount(2, $results);
        /** @var Page $first */
        $first = $results->offsetGet(0);
        /** @var Page $second */
        $second = $results->offsetGet(1);

        $this->assertEquals('alpha', $first->slug);
        $this->assertEquals('beta', $second->slug);
    }

    #[Test]
    public function it_excludes_slugs_from_ordered_links(): void
    {
        Page::factory()->create(['area' => PageArea::CHURCH, 'slug' => 'alpha', 'admin' => 'no']);
        Page::factory()->create(['area' => PageArea::CHURCH, 'slug' => 'beta', 'admin' => 'no']);
        Page::factory()->create(['area' => PageArea::CHURCH, 'slug' => 'gamma', 'admin' => 'no']);

        $results = $this->repository->orderedLinks(
            linkArea: 'church',
            slugToExclude: 'alpha',
            secondSlugToExclude: 'beta',
            extraExcludedSlugs: ['gamma']
        );

        $this->assertCount(0, $results);
    }

    #[Test]
    public function it_excludes_admin_pages_when_requested(): void
    {
        Page::factory()->create(['area' => PageArea::CHURCH, 'slug' => 'public', 'admin' => 'no']);
        Page::factory()->create(['area' => PageArea::CHURCH, 'slug' => 'private', 'admin' => 'yes']);

        $results = $this->repository->orderedLinks(
            linkArea: 'church',
            slugToExclude: null,
            secondSlugToExclude: null,
            excludeAdminPages: true
        );

        $this->assertCount(1, $results);
        /** @var Page $first */
        $first = $results->offsetGet(0);
        $this->assertEquals('public', $first->slug);
    }

    #[Test]
    public function it_returns_empty_collection_for_empty_area(): void
    {
        $results = $this->repository->orderedLinks('', null, null);
        $this->assertCount(0, $results);

        $results = $this->repository->randomLinks('', null, null);
        $this->assertCount(0, $results);
    }

    #[Test]
    public function it_returns_random_links_with_limit(): void
    {
        Page::factory()->count(10)->create(['area' => PageArea::CHURCH, 'admin' => 'no']);

        $results = $this->repository->randomLinks('church', null, null, false, [], 3);

        $this->assertCount(3, $results);
    }

    #[Test]
    public function it_selects_only_required_columns_and_loads_media(): void
    {
        Page::factory()->create([
            'area' => PageArea::CHURCH,
            'slug' => 'test-columns',
            'admin' => 'no',
            'body' => 'Long body content that should be excluded',
            'markdown' => 'Markdown content that should be excluded',
        ]);

        $results = $this->repository->orderedLinks('church', null, null);

        $this->assertCount(1, $results);
        /** @var Page $result */
        $result = $results->first();

        $this->assertEquals('test-columns', $result->slug);
        $this->assertEquals('no', $result->admin);

        // Verify excluded columns are not loaded in the attributes
        $attributes = $result->getAttributes();
        $this->assertArrayNotHasKey('body', $attributes);
        $this->assertArrayNotHasKey('markdown', $attributes);

        // Verify media relation is eager loaded
        $this->assertTrue($result->relationLoaded('media'));
    }
}
