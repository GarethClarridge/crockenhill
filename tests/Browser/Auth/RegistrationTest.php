<?php

namespace Tests\Browser\Auth;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class RegistrationTest extends DuskTestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->browse(function (Browser $browser) {
            $browser->logout();
        });
    }

    public function test_register_page_loads(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/register')
                ->assertVisible('input[type="text"]')
                ->assertVisible('input[type="email"]')
                ->assertVisible('input[type="password"]');
        });
    }

    public function test_user_can_register(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/register')
                ->type('#name', 'New Test User')
                ->type('#email', 'newuser@example.com')
                ->type('#password', 'StrongPass123!@#Unique')
                ->type('#password_confirmation', 'StrongPass123!@#Unique')
                ->press('Register')
                ->waitForLocation('/verify-email')
                ->assertPathIs('/verify-email');
        });
    }

    public function test_registration_fails_with_mismatched_passwords(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/register')
                ->type('#name', 'Test User')
                ->type('#email', 'mismatch@example.com')
                ->type('#password', 'password123')
                ->type('#password_confirmation', 'differentpassword')
                ->press('Register')
                ->waitForText('The password field confirmation does not match')
                ->assertSee('The password field confirmation does not match');
        });
    }
}
