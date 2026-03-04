<?php

namespace Tests\Browser;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class NavigationTest extends DuskTestCase
{
    use DatabaseTruncation;

    public function test_main_nav_links_work(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/')
                ->click('a[href="/christ"]')
                ->waitForLocation('/christ')
                ->assertPathIs('/christ');

            $browser->visit('/')
                ->click('a[href="/church"]')
                ->waitForLocation('/church')
                ->assertPathIs('/church');

            $browser->visit('/')
                ->click('a[href="/community"]')
                ->waitForLocation('/community')
                ->assertPathIs('/community');
        });
    }

    public function test_mobile_menu_opens_and_closes(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->resize(375, 812)
                ->visit('/')
                ->assertPresent('#mobile-menu')
                ->assertNotPresent('#mobile-menu[style="display: block;"]')
                ->click('button[aria-controls="mobile-menu"]')
                ->waitUntil('document.querySelector("#mobile-menu").style.display !== "none"')
                ->assertVisible('#mobile-menu')
                ->click('button[aria-controls="mobile-menu"]')
                ->waitUntil('document.querySelector("#mobile-menu").style.display === "none"')
                ->assertPresent('#mobile-menu')
                ->assertNotPresent('#mobile-menu[style="display: block;"]');
        });
    }

    public function test_members_link_visible_when_authenticated(): void
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/')
                ->assertPresent('a[href="/church/members"]');
        });
    }

    public function test_members_link_hidden_when_guest(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->logout()
                ->visit('/')
                ->assertNotPresent('a[href="/church/members"]');
        });
    }
}
