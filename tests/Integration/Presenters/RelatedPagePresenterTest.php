<?php

declare(strict_types=1);

namespace Tests\Integration\Presenters;

use App\Enums\PageArea;
use App\Models\Page;
use App\Presenters\RelatedPagePresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RelatedPagePresenterTest extends TestCase
{
    use RefreshDatabase;

    private RelatedPagePresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->presenter = app(RelatedPagePresenter::class);
    }

    #[Test]
    public function it_returns_presented_links_in_slug_order_for_an_area(): void
    {
        Page::factory()->create(['area' => PageArea::Church, 'slug' => 'beta', 'admin' => 'no']);
        Page::factory()->create(['area' => PageArea::Church, 'slug' => 'alpha', 'admin' => 'no']);
        Page::factory()->create(['area' => PageArea::Community, 'slug' => 'gamma', 'admin' => 'no']);

        $results = $this->presenter->ordered('church', null, null);

        $this->assertSame(['alpha', 'beta'], $results->pluck('slug')->all());
    }

    #[Test]
    public function it_excludes_requested_slugs_and_admin_pages(): void
    {
        Page::factory()->create(['area' => PageArea::Church, 'slug' => 'alpha', 'admin' => 'no']);
        Page::factory()->create(['area' => PageArea::Church, 'slug' => 'beta', 'admin' => 'no']);
        Page::factory()->create(['area' => PageArea::Church, 'slug' => 'gamma', 'admin' => 'no']);
        Page::factory()->create(['area' => PageArea::Church, 'slug' => 'private', 'admin' => 'yes']);

        $results = $this->presenter->ordered(
            linkArea: 'church',
            slugToExclude: 'alpha',
            secondSlugToExclude: 'beta',
            excludeAdminPages: true,
            extraExcludedSlugs: ['gamma'],
        );

        $this->assertCount(0, $results);
    }

    #[Test]
    public function it_returns_an_empty_collection_for_an_empty_area(): void
    {
        $this->assertCount(0, $this->presenter->ordered('', null, null));
        $this->assertCount(0, $this->presenter->random('', null, null));
    }

    #[Test]
    public function it_limits_random_links(): void
    {
        Page::factory()->count(10)->create(['area' => PageArea::Church, 'admin' => 'no']);

        $results = $this->presenter->random('church', null, null, false, [], 3);

        $this->assertCount(3, $results);
    }
}
