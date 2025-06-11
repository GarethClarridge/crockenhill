<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Crockenhill\User; // Assuming User model namespace
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function registration_page_can_be_rendered()
    {
        $response = $this->get(route('register'));

        $response->assertStatus(200);
        $response->assertSee('Name');
        $response->assertSee('Email Address'); // Or 'E-Mail Address'
        $response->assertSee('Password');
        $response->assertSee('Confirm Password');
    }

    #[Test]
    public function user_can_register_with_valid_data()
    {
        $password = 'password123';
        $userData = [
            'name' => 'Test User',
            'email' => 'testuser@example.com',
            'password' => $password,
            'password_confirmation' => $password,
        ];

        $response = $this->post(route('register'), $userData);

        $this->assertDatabaseHas('users', [
            'name' => 'Test User',
            'email' => 'testuser@example.com',
        ]);

        $user = User::where('email', 'testuser@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check($password, $user->password));

        $response->assertRedirect('/church/members'); // Or other intended route like '/home'
        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function user_cannot_register_with_existing_email()
    {
        $existingUser = User::factory()->create(['email' => 'existinguser@example.com']);

        $userData = [
            'name' => 'New User',
            'email' => 'existinguser@example.com', // Same email
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];

        $response = $this->post(route('register'), $userData);

        $response->assertRedirect(route('register')); // Or $response->assertRedirect('/register');
        $response->assertSessionHasErrors('email');
        $this->assertDatabaseCount('users', 1); // Only the original user should exist
        $this->assertEquals($existingUser->name, User::first()->name);
        $this->assertGuest();
    }

    #[Test]
    public function user_cannot_register_with_mismatched_passwords()
    {
        $userData = [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password456', // Mismatched
        ];

        $response = $this->post(route('register'), $userData);

        $response->assertRedirect(route('register'));
        $response->assertSessionHasErrors('password');
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    #[Test]
    public function user_cannot_register_with_invalid_email_format()
    {
        $userData = [
            'name' => 'Test User',
            'email' => 'not-an-email', // Invalid format
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];

        $response = $this->post(route('register'), $userData);

        $response->assertRedirect(route('register'));
        $response->assertSessionHasErrors('email');
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    #[Test]
    public function user_cannot_register_if_password_is_too_short()
    {
        // Assuming minimum password length is 8 (Laravel default)
        $shortPassword = Str::random(7);

        $userData = [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => $shortPassword,
            'password_confirmation' => $shortPassword,
        ];

        $response = $this->post(route('register'), $userData);

        $response->assertRedirect(route('register'));
        $response->assertSessionHasErrors('password');
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }
}
