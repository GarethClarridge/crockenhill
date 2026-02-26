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
        // Setup: Create required pages in community area
        /** @var Page $sundayEvenings */
        $sundayEvenings = Page::factory()->create([
            'slug' => 'sunday-evenings',
            'area' => PageArea::COMMUNITY,
        ]);
        /** @var Page $bibleStudy */
        $bibleStudy = Page::factory()->create([
            'slug' => 'bible-study',
            'area' => PageArea::COMMUNITY,
        ]);

        // Create an unrelated page that should not be returned
        Page::factory()->create([
            'slug' => 'unrelated-page',
            'area' => PageArea::COMMUNITY,
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
        // Setup: Create some required pages
        /** @var Page $coffeeCup */
        $coffeeCup = Page::factory()->create([
            'slug' => 'coffee-cup',
            'area' => PageArea::COMMUNITY,
        ]);
        /** @var Page $babyTalk */
        $babyTalk = Page::factory()->create([
            'slug' => 'baby-talk',
            'area' => PageArea::COMMUNITY,
        ]);

        // Create an unrelated page
        Page::factory()->create([
            'slug' => 'unrelated-community',
            'area' => PageArea::COMMUNITY,
        ]);

        $results = $this->service->forCommunity();

        $this->assertTrue($results->contains('id', $coffeeCup->id));
        $this->assertTrue($results->contains('id', $babyTalk->id));
        $this->assertFalse($results->contains('slug', 'unrelated-community'));
    }

    #[Test]
    public function it_returns_correct_pages_for_church(): void
    {
        // Setup: Create some required pages (note: these are in COMMUNITY area but used for church cards)
        /** @var Page $sundayMornings */
        $sundayMornings = Page::factory()->create([
            'slug' => 'sunday-mornings',
            'area' => PageArea::COMMUNITY,
        ]);

        // Create an unrelated page
        Page::factory()->create([
            'slug' => 'unrelated-church',
            'area' => PageArea::COMMUNITY,
        ]);

        $results = $this->service->forChurch();

        $this->assertTrue($results->contains('id', $sundayMornings->id));
        $this->assertFalse($results->contains('slug', 'unrelated-church'));
    }

    #[Test]
    public function it_returns_correct_church_links(): void
    {
        // Setup: Create public church pages
        /** @var Page $publicPage */
        $publicPage = Page::factory()->create([
            'area' => PageArea::CHURCH,
            'admin' => 'no',
            'slug' => 'public-info',
        ]);

        // Create policy pages that should be excluded
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

        // Create an admin page that should be excluded
        Page::factory()->create([
            'area' => PageArea::CHURCH,
            'admin' => 'yes',
            'slug' => 'admin-only-page',
        ]);

        // Create a page in a different area
        Page::factory()->create([
            'area' => PageArea::COMMUNITY,
            'admin' => 'no',
            'slug' => 'community-page',
        ]);

        $results = $this->service->churchLinks();

        $this->assertTrue($results->contains('id', $publicPage->id));
        $this->assertFalse($results->contains('slug', 'privacy-policy'));
        $this->assertFalse($results->contains('slug', 'safeguarding-policy'));
        $this->assertFalse($results->contains('slug', 'admin-only-page'));
        $this->assertFalse($results->contains('slug', 'community-page'));
    }
}
