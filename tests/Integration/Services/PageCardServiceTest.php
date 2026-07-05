<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Enums\PageArea;
use App\Models\Page;
use App\Services\Public\PageCardService;
use App\Services\Public\PageListCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PageCardServiceTest extends TestCase
{
    use RefreshDatabase;

    private PageCardService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetPageCardCaches();

        $this->service = app(PageCardService::class);
        DB::disableQueryLog();
    }

    #[Test]
    public function it_returns_correct_pages_for_home(): void
    {
        $this->deletePagesForSlugs([
            'sunday-evenings',
            'bible-study',
            'unrelated-page',
        ]);

        Page::factory()->create([
            'slug' => 'sunday-evenings',
            'area' => PageArea::Community,
            'admin' => 'no',
        ]);

        Page::factory()->create([
            'slug' => 'bible-study',
            'area' => PageArea::Community,
            'admin' => 'no',
        ]);

        Page::factory()->create([
            'slug' => 'unrelated-page',
            'area' => PageArea::Community,
            'admin' => 'no',
        ]);

        $results = $this->service->forHome();

        $this->assertCount(2, $results);
        $this->assertTrue($results->contains('slug', 'sunday-evenings'));
        $this->assertTrue($results->contains('slug', 'bible-study'));
        $this->assertFalse($results->contains('slug', 'unrelated-page'));
    }

    #[Test]
    public function it_uses_a_surface_specific_cache_for_home_cards(): void
    {
        $this->forgetPageCardCaches();

        $this->deletePagesForSlugs([
            'sunday-evenings',
            'bible-study',
            'unrelated-page',
        ]);

        Page::factory()->create([
            'slug' => 'sunday-evenings',
            'area' => PageArea::Community,
            'admin' => 'no',
        ]);

        Page::factory()->create([
            'slug' => 'bible-study',
            'area' => PageArea::Community,
            'admin' => 'no',
        ]);

        Page::factory()->create([
            'slug' => 'unrelated-page',
            'area' => PageArea::Community,
            'admin' => 'no',
        ]);

        DB::enableQueryLog();

        // First call should hit the database
        $results = $this->service->forHome();
        $queryCountAfterFirst = count(DB::getQueryLog());
        $this->assertGreaterThan(0, $queryCountAfterFirst);

        // Second call should be served from cache
        $resultsSecond = $this->service->forHome();
        $this->assertCount($queryCountAfterFirst, DB::getQueryLog());

        $this->assertTrue($results->contains('slug', 'sunday-evenings'));
        $this->assertTrue($results->contains('slug', 'bible-study'));
        $this->assertFalse($results->contains('slug', 'unrelated-page'));

        $this->assertEquals($results, $resultsSecond);
    }

    #[Test]
    public function it_returns_correct_pages_for_community(): void
    {
        $slugs = [
            'coffee-cup',
            'baby-talk',
            'sunday-mornings',
            'family-talk',
            'buzz-club',
            'christianity-explored',
            'bible-study',
            'carols-in-the-chequers',
        ];

        $this->deletePagesForSlugs([
            ...$slugs,
            'unrelated-community',
        ]);

        /** @var array<int, Page> $pages */
        $pages = [];
        foreach ($slugs as $slug) {
            $pages[] = Page::factory()->create([
                'area' => PageArea::Community,
                'slug' => $slug,
                'admin' => 'no',
            ]);
        }

        Page::factory()->create([
            'slug' => 'unrelated-community',
            'area' => PageArea::Community,
            'admin' => 'no',
        ]);

        $results = $this->service->forCommunity();

        $this->assertCount(count($slugs), $results);
        foreach ($pages as $page) {
            $this->assertTrue($results->contains('slug', $page->slug));
        }

        $this->assertFalse($results->contains('slug', 'unrelated-community'));
    }

    #[Test]
    public function it_returns_correct_pages_for_church(): void
    {
        $this->deletePagesForSlugs([
            'sunday-mornings',
            'sunday-evenings',
            'bible-study',
            'unrelated-church',
        ]);

        Page::factory()->create([
            'slug' => 'sunday-mornings',
            'area' => PageArea::Community,
            'admin' => 'no',
        ]);

        Page::factory()->create([
            'slug' => 'sunday-evenings',
            'area' => PageArea::Community,
            'admin' => 'no',
        ]);

        Page::factory()->create([
            'slug' => 'bible-study',
            'area' => PageArea::Community,
            'admin' => 'no',
        ]);

        Page::factory()->create([
            'slug' => 'unrelated-church',
            'area' => PageArea::Community,
            'admin' => 'no',
        ]);

        $results = $this->service->forChurch();

        $this->assertCount(3, $results);
        $this->assertTrue($results->contains('slug', 'sunday-mornings'));
        $this->assertTrue($results->contains('slug', 'sunday-evenings'));
        $this->assertTrue($results->contains('slug', 'bible-study'));
        $this->assertFalse($results->contains('slug', 'unrelated-church'));
    }

    #[Test]
    public function it_returns_church_links_excluding_admin_and_special_pages(): void
    {
        $this->deletePagesForSlugs([
            'about-us',
            'leadership',
            'privacy-policy',
            'safeguarding-policy',
            'admin-page',
            'community-page',
        ]);

        Page::factory()->create([
            'area' => PageArea::Church,
            'slug' => 'about-us',
            'admin' => 'no',
        ]);
        Page::factory()->create([
            'area' => PageArea::Church,
            'slug' => 'leadership',
            'admin' => 'no',
        ]);

        Page::factory()->create([
            'area' => PageArea::Church,
            'slug' => 'privacy-policy',
            'admin' => 'no',
        ]);
        Page::factory()->create([
            'area' => PageArea::Church,
            'slug' => 'safeguarding-policy',
            'admin' => 'no',
        ]);
        Page::factory()->create([
            'area' => PageArea::Church,
            'slug' => 'admin-page',
            'admin' => 'yes',
        ]);
        Page::factory()->create([
            'area' => PageArea::Community,
            'slug' => 'community-page',
            'admin' => 'no',
        ]);

        $results = $this->service->churchLinks();

        $this->assertTrue($results->contains('slug', 'about-us'));
        $this->assertTrue($results->contains('slug', 'leadership'));
        $this->assertFalse($results->contains('slug', 'privacy-policy'));
        $this->assertFalse($results->contains('slug', 'safeguarding-policy'));
        $this->assertFalse($results->contains('slug', 'admin-page'));
        $this->assertFalse($results->contains('slug', 'community-page'));
    }

    /**
     * @param  list<string>  $slugs
     */
    private function deletePagesForSlugs(array $slugs): void
    {
        Page::query()->whereIn('slug', $slugs)->delete();
    }

    private function forgetPageCardCaches(): void
    {
        /** @var PageListCache $repository */
        $repository = app(PageListCache::class);

        $repository->clearAreaCache(PageArea::Church);
        $repository->clearAreaCache(PageArea::Community);
        $repository->clearInternalCaches();
    }
}
