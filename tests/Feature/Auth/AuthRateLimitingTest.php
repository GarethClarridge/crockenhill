<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\Register;
use App\Livewire\Auth\ResetPassword;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthRateLimitingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function registration_is_rate_limited_after_multiple_attempts(): void
    {
        Notification::fake();

        $component = Livewire::test(Register::class)
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123');

        for ($i = 0; $i < 3; $i++) {
            $component->call('register');
            // Manually delete the user so we can register again with the same email
            User::where('email', 'test@example.com')->delete();
        }

        $component->call('register');
        $error = $component->get('error');
        $this->assertStringContainsString('Too many login attempts', $error);
    }

    #[Test]
    public function registration_is_rate_limited_even_with_different_emails(): void
    {
        Notification::fake();

        $component = Livewire::test(Register::class)
            ->set('name', 'Test User')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123');

        for ($i = 0; $i < 3; $i++) {
            $component->set('email', "user{$i}@example.com")
                ->call('register');
        }

        $component->set('email', 'another@example.com')
            ->call('register');

        $error = $component->get('error');
        $this->assertStringContainsString('Too many login attempts', $error);
    }

    #[Test]
    public function throttling_one_flow_does_not_throttle_another(): void
    {
        Notification::fake();

        // Throttle ForgotPassword
        $forgotComponent = Livewire::test(ForgotPassword::class)
            ->set('email', 'test@example.com');

        for ($i = 0; $i < 3; $i++) {
            $forgotComponent->call('sendResetLink');
        }

        $forgotComponent->call('sendResetLink');
        $this->assertStringContainsString('Too many login attempts', $forgotComponent->get('error'));

        // Register should still work for same email (though it's IP-based now)
        $registerComponent = Livewire::test(Register::class)
            ->set('name', 'Test User')
            ->set('email', 'test-unique@example.com')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123');

        $registerComponent->call('register')
            ->assertHasNoErrors();

        $this->assertEmpty($registerComponent->get('error'));
        $this->assertDatabaseHas('users', ['email' => 'test-unique@example.com']);
    }

    #[Test]
    public function forgot_password_is_rate_limited_after_multiple_attempts(): void
    {
        Notification::fake();

        $component = Livewire::test(ForgotPassword::class)
            ->set('email', 'test@example.com');

        for ($i = 0; $i < 3; $i++) {
            $component->call('sendResetLink');
        }

        $component->call('sendResetLink')
            ->assertSet('error', function ($value) {
                return str_contains($value, 'Too many login attempts');
            });
    }

    #[Test]
    public function reset_password_is_rate_limited_after_multiple_attempts(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $component = Livewire::test(ResetPassword::class, ['token' => $token])
            ->set('email', $user->email)
            ->set('password', 'new-password123')
            ->set('password_confirmation', 'new-password123');

        for ($i = 0; $i < 5; $i++) {
            // We use wrong token/email to cause failure and hit rate limiter
            $component->set('token', 'wrong-token')
                ->call('resetPassword');
        }

        $component->call('resetPassword')
            ->assertSet('error', function ($value) {
                return str_contains($value, 'Too many login attempts');
            });
    }
}
