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
        $this->service = new PageCardService;
    }

    #[Test]
    public function it_returns_correct_pages_for_home(): void
    {
        $page1 = Page::factory()->create(['area' => PageArea::COMMUNITY, 'slug' => 'sunday-evenings', 'admin' => 'no']);
        $page2 = Page::factory()->create(['area' => PageArea::COMMUNITY, 'slug' => 'bible-study', 'admin' => 'no']);

        $results = $this->service->forHome();

        $this->assertCount(2, $results);
        $this->assertTrue($results->contains($page1));
        $this->assertTrue($results->contains($page2));
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

        $pages = [];
        foreach ($slugs as $slug) {
            $pages[] = Page::factory()->create(['area' => PageArea::COMMUNITY, 'slug' => $slug, 'admin' => 'no']);
        }

        $results = $this->service->forCommunity();

        $this->assertCount(count($slugs), $results);
        foreach ($pages as $page) {
            $this->assertTrue($results->contains($page));
        }
    }

    #[Test]
    public function it_returns_correct_pages_for_church(): void
    {
        $p1 = Page::factory()->create(['area' => PageArea::COMMUNITY, 'slug' => 'sunday-mornings', 'admin' => 'no']);
        $p2 = Page::factory()->create(['area' => PageArea::COMMUNITY, 'slug' => 'sunday-evenings', 'admin' => 'no']);
        $p3 = Page::factory()->create(['area' => PageArea::COMMUNITY, 'slug' => 'bible-study', 'admin' => 'no']);

        $results = $this->service->forChurch();

        $this->assertCount(3, $results);
        $this->assertTrue($results->contains($p1));
        $this->assertTrue($results->contains($p2));
        $this->assertTrue($results->contains($p3));
    }

    #[Test]
    public function it_returns_church_links_excluding_admin_and_special_pages(): void
    {
        // Pages that should be included
        $page1 = Page::factory()->create(['area' => PageArea::CHURCH, 'slug' => 'about-us', 'admin' => 'no']);
        $page2 = Page::factory()->create(['area' => PageArea::CHURCH, 'slug' => 'leadership', 'admin' => 'no']);

        // Pages that should be excluded
        Page::factory()->create(['area' => PageArea::CHURCH, 'slug' => 'privacy-policy', 'admin' => 'no']);
        Page::factory()->create(['area' => PageArea::CHURCH, 'slug' => 'safeguarding-policy', 'admin' => 'no']);
        Page::factory()->create(['area' => PageArea::CHURCH, 'slug' => 'admin-page', 'admin' => 'yes']);
        Page::factory()->create(['area' => PageArea::COMMUNITY, 'slug' => 'community-page', 'admin' => 'no']);

        $results = $this->service->churchLinks();

        $this->assertCount(2, $results);
        $this->assertTrue($results->contains($page1));
        $this->assertTrue($results->contains($page2));
    }
}
