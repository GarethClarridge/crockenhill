<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Meeting;
use App\Models\Page;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class PublicPagesTest extends DuskTestCase
{
    use DatabaseTruncation;

    public function test_sermons_index_loads(): void
    {
        $sermon = Sermon::factory()->create(['title' => 'A Dusk Test Sermon']);

        $this->browse(function (Browser $browser) use ($sermon) {
            $browser->visit('/christ/sermons')
                ->assertSee($sermon->title);
        });
    }

    public function test_individual_sermon_page_loads(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Individual Sermon Page Test',
            'slug' => 'individual-sermon-page-test',
            'reference' => 'John 3:16',
        ]);

        $this->browse(function (Browser $browser) use ($sermon) {
            $browser->visit('/christ/sermons/'.$sermon->slug)
                ->assertSee($sermon->title)
                ->assertSee($sermon->reference);
        });
    }

    public function test_community_meeting_page_loads(): void
    {
        $meeting = Meeting::factory()->create([
            'slug' => 'dusk-test-meeting',
            'type' => 'Adults',
            'day' => 'Thursday',
            'location' => 'Church hall',
            'who' => 'All welcome',
            'start_time' => '11:00:00',
            'end_time' => null,
        ]);

        $this->browse(function (Browser $browser) use ($meeting) {
            $browser->visit('/community/'.$meeting->slug)
                ->assertSee('Thursday')
                ->assertSee('Church hall');
        });
    }

    public function test_footer_sermons_link_navigates(): void
    {
        Sermon::factory()->create(['title' => 'Footer Link Sermon']);

        $this->browse(function (Browser $browser) {
            $browser->visit('/')
                ->tap(fn ($b) => $b->script("document.querySelector('a[href=\"/christ/sermons/evening\"]').click()"))
                ->waitForLocation('/christ/sermons/evening')
                ->assertPathIs('/christ/sermons/evening');
        });
    }

    public function test_footer_find_us_link_navigates(): void
    {
        Page::factory()->create([
            'slug' => 'find-us',
            'heading' => 'Find us',
            'area' => 'church',
        ]);

        $this->browse(function (Browser $browser) {
            $browser->visit('/')
                ->tap(fn ($b) => $b->script("document.querySelector('a[href=\"/church/find-us\"]').click()"))
                ->waitForLocation('/church/find-us')
                ->assertPathIs('/church/find-us')
                ->assertSee('Find us');
        });
    }

    public function test_404_page_renders(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/christ/this-page-does-not-exist-at-all')
                ->assertSee("Sorry, that page doesn't seem to exist");
        });
    }
}
