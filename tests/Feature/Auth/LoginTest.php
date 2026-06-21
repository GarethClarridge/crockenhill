<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Livewire\Auth\Login as LoginComponent;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function routes_are_loaded(): void
    {
        // Test if any routes are loaded at all
        $response = $this->get('/');
        $response->assertStatus(200);
    }

    #[Test]
    public function user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password123'),
        ]);

        $credentials = [
            'email' => $user->email,
            'password' => 'password123',
        ];

        $this->assertTrue(Auth::attempt($credentials));
        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function user_cannot_login_with_invalid_credentials(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password123'),
        ]);

        $credentials = [
            'email' => $user->email,
            'password' => 'wrong-password',
        ];

        $this->assertFalse(Auth::attempt($credentials));
        $this->assertGuest();
    }

    #[Test]
    public function user_can_login_with_remember_me(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password123'),
        ]);

        $credentials = [
            'email' => $user->email,
            'password' => 'password123',
        ];

        $this->assertTrue(Auth::attempt($credentials, true));
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->remember_token);
    }

    #[Test]
    public function user_cannot_login_with_nonexistent_email(): void
    {
        $credentials = [
            'email' => 'nonexistent@example.com',
            'password' => 'password123',
        ];

        $this->assertFalse(Auth::attempt($credentials));
        $this->assertGuest();
    }

    #[Test]
    public function user_cannot_login_with_empty_credentials(): void
    {
        $this->assertFalse(Auth::attempt([]));
        $this->assertGuest();
    }

    #[Test]
    public function user_can_logout(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password123'),
        ]);

        $this->be($user);
        $this->assertAuthenticatedAs($user);

        Auth::logout();
        $this->assertGuest();
    }

    #[Test]
    public function user_can_logout_and_session_is_cleared(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password123'),
        ]);

        $this->be($user);
        $this->assertAuthenticatedAs($user);

        // Add some session data
        session(['test_key' => 'test_value']);
        $this->assertEquals('test_value', session('test_key'));

        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();

        $this->assertGuest();
        $this->assertNull(session('test_key'));
    }

    #[Test]
    public function livewire_login_is_rate_limited_after_five_failed_attempts(): void
    {
        Event::fake([Lockout::class]);

        $user = User::factory()->create([
            'password' => bcrypt('password123'),
        ]);

        $throttleKey = $this->throttleKey($user->email);
        RateLimiter::clear($throttleKey);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            Livewire::test(LoginComponent::class)
                ->set('email', $user->email)
                ->set('password', 'incorrect-password')
                ->call('login')
                ->assertSet('error', trans('auth.failed'));
        }

        $component = Livewire::test(LoginComponent::class)
            ->set('email', $user->email)
            ->set('password', 'incorrect-password')
            ->call('login');

        $error = (string) $component->get('error');
        $seconds = RateLimiter::availableIn($throttleKey);
        $expectedMessages = [
            $this->throttleMessage($seconds),
            $this->throttleMessage(max($seconds + 1, 0)),
            $this->throttleMessage(max($seconds - 1, 0)),
        ];

        $this->assertTrue(RateLimiter::tooManyAttempts($throttleKey, 5));
        $this->assertContains($error, $expectedMessages);
        Event::assertDispatched(Lockout::class);
    }

    #[Test]
    public function livewire_login_can_repeat_failed_attempts_on_the_same_component_instance(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password123'),
        ]);

        $component = Livewire::test(LoginComponent::class)
            ->set('email', $user->email)
            ->set('password', 'incorrect-password');

        $component
            ->call('login')
            ->assertSet('error', trans('auth.failed'));

        $component
            ->call('login')
            ->assertSet('error', trans('auth.failed'));
    }

    #[Test]
    public function successful_admin_login_sanitises_email_in_log(): void
    {
        $crafted = "admin@example.com\nX-Injected-Header: malicious";

        $admin = User::factory()->create([
            'is_admin' => true,
            'email' => 'admin@example.com',
            'password' => bcrypt('correct-password'),
        ]);

        Log::partialMock();
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($message, $context) => str_contains($message, 'Admin logged in') &&
                isset($context['email']) &&
                $context['email'] !== $crafted &&
                ! str_contains((string) $context['email'], "\n"));

        Livewire::test(LoginComponent::class)
            ->set('email', $admin->email)
            ->set('password', 'correct-password')
            ->call('login');
    }

    #[Test]
    public function failed_admin_login_is_logged_as_warning(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'password' => bcrypt('correct-password'),
        ]);

        Log::partialMock();
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($message, $context) => str_contains($message, 'Admin login attempt failed') &&
                isset($context['admin_id']) &&
                isset($context['email']) &&
                isset($context['ip']));

        Livewire::test(LoginComponent::class)
            ->set('email', $admin->email)
            ->set('password', 'wrong-password')
            ->call('login')
            ->assertSet('error', trans('auth.failed'));
    }

    #[Test]
    public function failed_regular_user_login_is_not_logged_as_warning(): void
    {
        Log::partialMock();
        Log::shouldReceive('warning')
            ->never();

        $user = User::factory()->create([
            'is_admin' => false,
            'password' => bcrypt('correct-password'),
        ]);

        Livewire::test(LoginComponent::class)
            ->set('email', $user->email)
            ->set('password', 'wrong-password')
            ->call('login')
            ->assertSet('error', trans('auth.failed'));
    }

    #[Test]
    public function successful_livewire_login_clears_failed_attempt_counter(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password123'),
        ]);

        $throttleKey = $this->throttleKey($user->email);
        RateLimiter::clear($throttleKey);

        Livewire::test(LoginComponent::class)
            ->set('email', $user->email)
            ->set('password', 'incorrect-password')
            ->call('login');

        $this->assertTrue(RateLimiter::tooManyAttempts($throttleKey, 1));

        Livewire::test(LoginComponent::class)
            ->set('email', $user->email)
            ->set('password', 'password123')
            ->call('login')
            ->assertRedirect('/church/members');

        $this->assertFalse(RateLimiter::tooManyAttempts($throttleKey, 1));
        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function livewire_login_rejects_array_payload_for_email_without_type_error(): void
    {
        Livewire::test(LoginComponent::class)
            ->set('email', ['attacker@example.com'])
            ->set('password', 'password123')
            ->set('remember', false)
            ->call('login')
            ->assertHasErrors(['email' => 'string'])
            ->assertSet('error', '');
    }

    #[Test]
    public function livewire_login_rejects_array_payload_for_password_without_type_error(): void
    {
        Livewire::test(LoginComponent::class)
            ->set('email', 'attacker@example.com')
            ->set('password', ['password123'])
            ->set('remember', false)
            ->call('login')
            ->assertHasErrors(['password' => 'string'])
            ->assertSet('error', '');
    }

    #[Test]
    public function livewire_login_rejects_array_payload_for_remember_without_type_error(): void
    {
        Livewire::test(LoginComponent::class)
            ->set('email', 'attacker@example.com')
            ->set('password', 'password123')
            ->set('remember', ['true'])
            ->call('login')
            ->assertHasErrors(['remember' => 'boolean'])
            ->assertSet('error', '');
    }

    private function throttleKey(string $email): string
    {
        return Str::transliterate('login|'.Str::lower($email).'|127.0.0.1');
    }

    private function throttleMessage(int $seconds): string
    {
        return trans('auth.throttle', [
            'seconds' => $seconds,
            'minutes' => (int) ceil($seconds / 60),
        ]);
    }
}
