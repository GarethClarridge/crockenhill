<?php

declare(strict_types=1);

namespace Tests\Browser\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class ForgotPasswordTest extends DuskTestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('forgot|test@example.com|127.0.0.1');
    }

    public function test_user_can_request_password_reset(): void
    {
        $user = User::factory()->create([
            'email' => 'reset@example.com',
        ]);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->visit('/forgot-password')
                ->type('input[type="email"]', $user->email)
                ->press('Send Password Reset Link')
                ->waitForText('We have emailed your password reset link')
                ->assertSee('We have emailed your password reset link');
        });
    }
}
