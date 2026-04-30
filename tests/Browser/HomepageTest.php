<?php

declare(strict_types=1);

namespace Tests\Browser;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class HomepageTest extends DuskTestCase
{
    use DatabaseTruncation;

    public function test_homepage_loads(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/')
                ->assertSee('Crockenhill Baptist Church')
                ->assertSee('Worshipping God')
                ->assertSee('Strengthening believers')
                ->assertSee('Proclaiming Jesus Christ to all');
        });
    }

    public function test_hero_links_navigate(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/')
                ->assertPresent('a[href="#worshipping-god"]')
                ->assertPresent('a[href="#strengthening-believers"]')
                ->assertPresent('a[href="#proclaiming-jesus-christ-to-all"]');
        });
    }
}
