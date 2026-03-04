<?php

namespace Tests\Browser;

use App\Models\Page;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class PageCardsTest extends DuskTestCase
{
    use DatabaseTruncation;

    // Pages required by PageCardService::forHome()
    private function createHomepagePages(): void
    {
        Page::factory()->create([
            'slug' => 'sunday-evenings',
            'heading' => 'Sunday evenings',
            'description' => 'We meet on Sunday evenings at 6pm.',
            'area' => 'community',
        ]);

        Page::factory()->create([
            'slug' => 'bible-study',
            'heading' => 'Bible study',
            'description' => 'Our weekly small group Bible studies.',
            'area' => 'community',
        ]);
    }

    // Pages required by PageCardService::forCommunity()
    private function createCommunityPages(): void
    {
        Page::factory()->create([
            'slug' => 'coffee-cup',
            'heading' => 'Coffee Cup',
            'description' => 'A weekly meeting for coffee, cake and chat.',
            'area' => 'community',
        ]);

        Page::factory()->create([
            'slug' => 'baby-talk',
            'heading' => 'Baby Talk',
            'description' => 'A group for those with babies and toddlers.',
            'area' => 'community',
        ]);

        Page::factory()->create([
            'slug' => 'family-talk',
            'heading' => 'Family Talk',
            'description' => 'Family Talk is for all the family.',
            'area' => 'community',
        ]);

        Page::factory()->create([
            'slug' => 'buzz-club',
            'heading' => 'Buzz Club',
            'description' => 'A summer holiday club for primary school children.',
            'area' => 'community',
        ]);
    }

    public function test_homepage_shows_sunday_evenings_page_card(): void
    {
        $this->createHomepagePages();

        $this->browse(function (Browser $browser) {
            $browser->visit('/')
                ->assertSee('Sunday evenings');
        });
    }

    public function test_homepage_shows_bible_study_page_card(): void
    {
        $this->createHomepagePages();

        $this->browse(function (Browser $browser) {
            $browser->visit('/')
                ->assertSee('Bible study');
        });
    }

    public function test_homepage_page_card_links_navigate(): void
    {
        $this->createHomepagePages();

        $this->browse(function (Browser $browser) {
            $browser->visit('/')
                ->tap(fn ($b) => $b->script("document.querySelector('a[href=\"/community/sunday-evenings\"]').click()"))
                ->waitForLocation('/community/sunday-evenings')
                ->assertPathIs('/community/sunday-evenings');
        });
    }

    public function test_community_page_shows_coffee_cup_card(): void
    {
        $this->createCommunityPages();

        $this->browse(function (Browser $browser) {
            $browser->visit('/community')
                ->assertSee('Coffee Cup');
        });
    }

    public function test_community_page_shows_baby_talk_card(): void
    {
        $this->createCommunityPages();

        $this->browse(function (Browser $browser) {
            $browser->visit('/community')
                ->assertSee('Baby Talk');
        });
    }

    public function test_community_page_shows_family_talk_card(): void
    {
        $this->createCommunityPages();

        $this->browse(function (Browser $browser) {
            $browser->visit('/community')
                ->assertSee('Family Talk');
        });
    }

    public function test_community_page_shows_buzz_club_card(): void
    {
        $this->createCommunityPages();

        $this->browse(function (Browser $browser) {
            $browser->visit('/community')
                ->assertSee('Buzz Club');
        });
    }

    public function test_community_page_card_navigates_correctly(): void
    {
        $this->createCommunityPages();

        $this->browse(function (Browser $browser) {
            $browser->visit('/community')
                ->tap(fn ($b) => $b->script("document.querySelector('a[href=\"/community/coffee-cup\"]').click()"))
                ->waitForLocation('/community/coffee-cup')
                ->assertPathIs('/community/coffee-cup');
        });
    }
}
