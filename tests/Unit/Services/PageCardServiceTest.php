<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\PageArea;
use App\Models\Page;
use App\Services\PageCardService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PageCardServiceTest extends TestCase
{
    use DatabaseTransactions;

    private PageCardService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PageCardService::class);
    }

    #[Test]
    public function it_returns_correct_pages_for_home(): void
    {
        $this->deletePagesForSlugs([
            'sunday-evenings',
            'bible-study',
            'unrelated-page',
        ]);

        /** @var Page $sundayEvenings */
        $sundayEvenings = Page::factory()->create([
            'slug' => 'sunday-evenings',
            'area' => PageArea::COMMUNITY,
            'admin' => 'no',
        ]);

        /** @var Page $bibleStudy */
        $bibleStudy = Page::factory()->create([
            'slug' => 'bible-study',
            'area' => PageArea::COMMUNITY,
            'admin' => 'no',
        ]);

        Page::factory()->create([
            'slug' => 'unrelated-page',
            'area' => PageArea::COMMUNITY,
            'admin' => 'no',
        ]);

        $results = $this->service->forHome();

        $this->assertCount(2, $results);
        $this->assertTrue($results->contains('id', $sundayEvenings->id));
        $this->assertTrue($results->contains('id', $bibleStudy->id));
        $this->assertFalse($results->contains('slug', 'unrelated-page'));
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
                'area' => PageArea::COMMUNITY,
                'slug' => $slug,
                'admin' => 'no',
            ]);
        }

        Page::factory()->create([
            'slug' => 'unrelated-community',
            'area' => PageArea::COMMUNITY,
            'admin' => 'no',
        ]);

        $results = $this->service->forCommunity();

        $this->assertCount(count($slugs), $results);
        foreach ($pages as $page) {
            $this->assertTrue($results->contains('id', $page->id));
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

        /** @var Page $sundayMornings */
        $sundayMornings = Page::factory()->create([
            'slug' => 'sunday-mornings',
            'area' => PageArea::COMMUNITY,
            'admin' => 'no',
        ]);

        /** @var Page $sundayEvenings */
        $sundayEvenings = Page::factory()->create([
            'slug' => 'sunday-evenings',
            'area' => PageArea::COMMUNITY,
            'admin' => 'no',
        ]);

        /** @var Page $bibleStudy */
        $bibleStudy = Page::factory()->create([
            'slug' => 'bible-study',
            'area' => PageArea::COMMUNITY,
            'admin' => 'no',
        ]);

        Page::factory()->create([
            'slug' => 'unrelated-church',
            'area' => PageArea::COMMUNITY,
            'admin' => 'no',
        ]);

        $results = $this->service->forChurch();

        $this->assertCount(3, $results);
        $this->assertTrue($results->contains('id', $sundayMornings->id));
        $this->assertTrue($results->contains('id', $sundayEvenings->id));
        $this->assertTrue($results->contains('id', $bibleStudy->id));
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

        /** @var Page $page1 */
        $page1 = Page::factory()->create([
            'area' => PageArea::CHURCH,
            'slug' => 'about-us',
            'admin' => 'no',
        ]);
        /** @var Page $page2 */
        $page2 = Page::factory()->create([
            'area' => PageArea::CHURCH,
            'slug' => 'leadership',
            'admin' => 'no',
        ]);

        Page::factory()->create([
            'area' => PageArea::CHURCH,
            'slug' => 'privacy-policy',
            'admin' => 'no',
        ]);
        Page::factory()->create([
            'area' => PageArea::CHURCH,
            'slug' => 'safeguarding-policy',
            'admin' => 'no',
        ]);
        Page::factory()->create([
            'area' => PageArea::CHURCH,
            'slug' => 'admin-page',
            'admin' => 'yes',
        ]);
        Page::factory()->create([
            'area' => PageArea::COMMUNITY,
            'slug' => 'community-page',
            'admin' => 'no',
        ]);

        $results = $this->service->churchLinks();

        $this->assertTrue($results->contains('id', $page1->id));
        $this->assertTrue($results->contains('id', $page2->id));
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
}
