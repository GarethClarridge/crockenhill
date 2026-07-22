<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class MembersTest extends DuskTestCase
{
    use DatabaseTruncation;

    public function test_members_area_requires_auth(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/church/members')
                ->waitForLocation('/login')
                ->assertPathIs('/login');
        });
    }

    public function test_authenticated_user_sees_welcome_message(): void
    {
        $user = User::factory()->create([
            'name' => 'Jane Doe',
        ]);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/church/members')
                ->assertSee('Welcome back');
        });
    }

    public function test_admin_sees_admin_actions(): void
    {
        $admin = User::factory()->crockenhillAdmin()->create();

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/church/members')
                ->assertSee('Services, sermons and songs')
                ->assertSee('Services')
                ->assertSee('Needs attention')
                ->assertSee('Manage sermons')
                ->assertSee('Song catalogue');
        });
    }

    public function test_non_admin_only_sees_account_section(): void
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/church/members')
                ->assertDontSee('Needs attention')
                ->assertDontSee('Manage sermons')
                ->assertSee('Welcome back');
        });
    }

    public function test_admin_needs_attention_link_navigates_to_services(): void
    {
        $admin = User::factory()->crockenhillAdmin()->create();

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/church/members')
                ->clickLink('Needs attention')
                ->waitForLocation('/admin/services')
                ->assertPathIs('/admin/services');
        });
    }

    public function test_admin_manage_sermons_link_navigates(): void
    {
        $admin = User::factory()->crockenhillAdmin()->create();

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/church/members')
                ->clickLink('Manage sermons')
                ->waitForLocation('/admin/sermons')
                ->assertPathIs('/admin/sermons');
        });
    }
}
