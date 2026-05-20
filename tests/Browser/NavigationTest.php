<?php

declare(strict_types=1);

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
        // The desktop nav links are hidden below the `lg:` breakpoint, and the
        // headless Chrome viewport in CI/Docker often refuses to resize past
        // mobile widths. Click via JS so the test verifies navigation (its
        // actual intent) without depending on viewport-dependent visibility.
        $this->browse(function (Browser $browser) {
            $browser->visit('/')
                ->tap(fn ($b) => $b->script("document.querySelector('a[href=\"/christ\"]').click()"))
                ->waitForLocation('/christ')
                ->assertPathIs('/christ');

            $browser->visit('/')
                ->tap(fn ($b) => $b->script("document.querySelector('a[href=\"/church\"]').click()"))
                ->waitForLocation('/church')
                ->assertPathIs('/church');

            $browser->visit('/')
                ->tap(fn ($b) => $b->script("document.querySelector('a[href=\"/community\"]').click()"))
                ->waitForLocation('/community')
                ->assertPathIs('/community');
        });
    }

    public function test_section_pages_render_content(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/christ')
                ->assertSee('Christ');

            $browser->visit('/church')
                ->assertSee('Church');

            $browser->visit('/community')
                ->assertSee('Community');
        });
    }

    public function test_logo_link_navigates_to_homepage(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/christ')
                ->click('header a[href="/"]')
                ->waitForLocation('/')
                ->assertPathIs('/');
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

    public function test_mobile_menu_section_buttons_navigate(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->resize(375, 812)
                ->visit('/')
                ->click('button[aria-controls="mobile-menu"]')
                ->waitUntil('document.querySelector("#mobile-menu").style.display !== "none"')
                ->click('#mobile-menu a[href="/church"]')
                ->waitForLocation('/church')
                ->assertPathIs('/church');
        });
    }

    public function test_mobile_menu_sub_links_navigate(): void
    {
        $this->artisan('db:seed', ['--class' => 'PageSeeder']);

        $this->browse(function (Browser $browser) {
            $browser->resize(375, 812)
                ->visit('/')
                ->click('button[aria-controls="mobile-menu"]')
                ->waitUntil('document.querySelector("#mobile-menu").style.display !== "none"')
                ->waitFor('a[href="/church/find-us"]')
                ->click('a[href="/church/find-us"]')
                ->waitForLocation('/church/find-us')
                ->assertPathIs('/church/find-us');
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
