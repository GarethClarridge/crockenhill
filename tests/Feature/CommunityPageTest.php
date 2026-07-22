<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PageArea;
use App\Models\Page;
use App\Services\Public\PageCardService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CommunityPageTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure Cache is clear for page card rails before each test
        PageCardService::forgetRailCaches();
    }

    #[Test]
    public function it_renders_community_landing_page_successfully(): void
    {
        $response = $this->get('/community');

        $response->assertStatus(200);
    }

    #[Test]
    public function it_renders_all_expected_headings_and_sections(): void
    {
        $response = $this->get('/community');

        $response->assertStatus(200);
        $response->assertSee('Community');
        $response->assertSee('I want to meet local people');
        // Use false for escaping to match raw apostrophe
        $response->assertSee("I've got children", false);
        $response->assertSee('I want to find out more about Jesus');
        $response->assertSee('Occasional events');
    }

    #[Test]
    public function it_renders_configured_cards_when_they_exist(): void
    {
        $coffeeCup = Page::factory()->create([
            'slug' => 'coffee-cup',
            'heading' => 'Coffee Cup',
            'description' => 'Come along on Thursday mornings for a free hot drink and a friendly chat.',
            'area' => PageArea::Community,
        ]);

        $babyTalk = Page::factory()->create([
            'slug' => 'baby-talk',
            'heading' => 'Baby Talk',
            'description' => 'A group for parents, carers, and toddlers on Monday mornings.',
            'area' => PageArea::Community,
        ]);

        $response = $this->get('/community');

        $response->assertStatus(200);

        // Verify the cards are rendered
        $response->assertSee('Coffee Cup');
        $response->assertSee('Come along on Thursday mornings');
        $response->assertSee('Learn about Coffee Cup');

        $response->assertSee('Baby Talk');
        $response->assertSee('A group for parents, carers, and toddlers');
        $response->assertSee('Learn about Baby Talk');
    }

    #[Test]
    public function it_does_not_render_cards_that_do_not_exist(): void
    {
        // When no pages are in database, the page should still load successfully
        // but not contain specific page headings/descriptions.
        $response = $this->get('/community');

        $response->assertStatus(200);
        $response->assertDontSee('Learn about Coffee Cup');
        $response->assertDontSee('Learn about Baby Talk');
    }

    #[Test]
    public function it_handles_cards_moved_to_different_page_areas(): void
    {
        // Even if Sunday Mornings is placed under Church area, slug-based matching
        // should resolve and display it on the Community landing page
        Page::factory()->create([
            'slug' => 'sunday-mornings',
            'heading' => 'Sunday Mornings',
            'description' => 'Our main service is at 10:30am every Sunday.',
            'area' => PageArea::Church, // Moved to Church area
        ]);

        $response = $this->get('/community');

        $response->assertStatus(200);
        $response->assertSee('Sunday Mornings');
        $response->assertSee('Our main service is at 10:30am');
        $response->assertSee('Learn about Sunday Mornings');
    }

    #[Test]
    public function it_excludes_admin_only_pages(): void
    {
        Page::factory()->create([
            'slug' => 'buzz-club',
            'heading' => 'Buzz Club',
            'description' => 'Fun and games for primary school kids.',
            'area' => PageArea::Community,
            'admin' => 'yes', // Admin only
        ]);

        $response = $this->get('/community');

        $response->assertStatus(200);

        // "Buzz Club" is hardcoded in the FAQ schema questions/answers on the page,
        // so we assert that the card button link is not seen, proving the card itself is excluded.
        $response->assertDontSee('Learn about Buzz Club');
    }
}
