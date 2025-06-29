<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

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
} 