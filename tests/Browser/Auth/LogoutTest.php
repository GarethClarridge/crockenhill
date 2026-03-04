<?php

namespace Tests\Browser\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class LogoutTest extends DuskTestCase
{
    use DatabaseTruncation;

    public function test_user_can_log_out(): void
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/church/members')
                ->click('button[name="logout"]')
                ->waitForLocation('/')
                ->assertPathIs('/')
                ->assertGuest();
        });
    }
}
