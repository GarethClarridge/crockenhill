<?php

namespace Tests\Browser\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class LoginTest extends DuskTestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure no leftover session between tests
        $this->browse(function (Browser $browser) {
            $browser->logout();
        });
    }

    public function test_user_can_see_login_form(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/login')
                ->assertVisible('input[type="email"]')
                ->assertVisible('input[type="password"]');
        });
    }

    public function test_user_can_log_in(): void
    {
        $user = User::factory()->create([
            'email' => 'member@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->visit('/login')
                ->type('input[type="email"]', $user->email)
                ->type('input[type="password"]', 'password')
                ->press('Login')
                ->waitForLocation('/church/members')
                ->assertPathIs('/church/members');
        });
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->create([
            'email' => 'wrongpass@example.com',
        ]);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->visit('/login')
                ->type('input[type="email"]', $user->email)
                ->type('input[type="password"]', 'wrong-password')
                ->press('Login')
                ->waitForText('These credentials do not match our records')
                ->assertSee('These credentials do not match our records');
        });
    }

    public function test_rate_limiting_blocks_after_five_attempts(): void
    {
        $email = 'ratelimit@example.com';

        RateLimiter::clear('login|'.$email.'|127.0.0.1');

        $this->browse(function (Browser $browser) use ($email) {
            for ($i = 0; $i < 5; $i++) {
                $browser->visit('/login')
                    ->type('input[type="email"]', $email)
                    ->type('input[type="password"]', 'wrong-password')
                    ->press('Login')
                    ->pause(300);
            }

            $browser->visit('/login')
                ->type('input[type="email"]', $email)
                ->type('input[type="password"]', 'wrong-password')
                ->press('Login')
                ->waitForText('Too many login attempts')
                ->assertSee('Too many login attempts');
        });
    }

    public function test_remember_me_persists_session(): void
    {
        $user = User::factory()->create([
            'email' => 'remember@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->visit('/login')
                ->type('input[type="email"]', $user->email)
                ->type('input[type="password"]', 'password')
                ->check('#remember')
                ->press('Login')
                ->waitForLocation('/church/members')
                ->assertHasCookie('remember_web_'.sha1('Illuminate\Auth\SessionGuard'));
        });
    }
}
