<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use DatabaseTransactions;

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
}
