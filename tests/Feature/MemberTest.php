<?php

namespace Tests\Feature;

use Tests\TestCase;
use Crockenhill\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class MemberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Ensure necessary routes are available (like auth routes)
        // If Auth::routes() is conditional in web.php, ensure conditions are met
        // For this project, Auth::routes(['reset' => false]); is in web.php
    }

    // Test Member Registration

    /** @test */
    public function a_guest_can_view_registration_form()
    {
        $response = $this->get('http://localhost/register'); // Using explicit URL due to previous APP_URL issues
        $response->assertStatus(200);
        $response->assertViewIs('auth.register'); // Common view name
    }

    /** @test */
    public function a_guest_can_register()
    {
        $userData = [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];

        $response = $this->post('http://localhost/register', $userData);

        $response->assertRedirect('http://localhost/church/members'); // Default redirect for new users in this app
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
            'name' => 'Test User',
        ]);
    }

    /** @test */
    public function registration_requires_name_email_password()
    {
        $response = $this->post('http://localhost/register', [
            'name' => '',
            'email' => '',
            'password' => '',
            'password_confirmation' => '',
        ]);
        $response->assertSessionHasErrors(['name', 'email', 'password']);

        $response = $this->post('http://localhost/register', [
            'name' => 'Test User',
            'email' => 'not-an-email',
            'password' => 'pass',
            'password_confirmation' => 'different',
        ]);
        $response->assertSessionHasErrors(['email', 'password']);
    }

    // Test Member Authentication (Login/Logout)

    /** @test */
    public function a_user_can_view_login_form()
    {
        $response = $this->get('http://localhost/login');
        $response->assertStatus(200);
        $response->assertViewIs('auth.login'); // Common view name
    }

    /** @test */
    public function a_registered_user_can_login()
    {
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);

        $response = $this->post('http://localhost/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $response->assertRedirect('http://localhost/church/members');
        $this->assertAuthenticatedAs($user);
    }

    /** @test */
    public function login_requires_valid_credentials()
    {
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);

        // Test with non-existent email
        $response = $this->post('http://localhost/login', [
            'email' => 'nonexistent@example.com',
            'password' => 'password123',
        ]);
        $response->assertSessionHasErrors('email');
        $this->assertGuest();

        // Test with incorrect password
        $response = $this->post('http://localhost/login', [
            'email' => $user->email,
            'password' => 'wrongpassword',
        ]);
        $response->assertSessionHasErrors('email'); // Laravel's default is to error on 'email' for bad creds
        $this->assertGuest();
    }

    /** @test */
    public function an_authenticated_user_can_logout()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->post('http://localhost/logout');

        $response->assertRedirect('http://localhost'); // Usually redirects to home
        $this->assertGuest();
    }

    // Test Member Area Access Control

    /** @test */
    public function guests_cannot_access_member_area()
    {
        $memberRoutes = [
            'http://localhost/church/members',
            // Add other member-specific routes here if known, e.g., 'http://localhost/members/profile'
        ];

        foreach ($memberRoutes as $route) {
            $response = $this->get($route);
            $response->assertRedirect('http://localhost/login');
        }
    }

    /** @test */
    public function authenticated_users_can_access_member_area()
    {
        $user = User::factory()->create();
        // Ensure a page for the members area exists, as MemberController@home tries to load it
        \Crockenhill\Page::factory()->create([ // Added full namespace
            'slug' => 'members-home',
            'title' => 'Members Home',
            'area' => 'church', // Or a relevant area
            'heading' => 'Members Area',
            'description' => 'Welcome to the members area.',
            'content' => '<p>Members content here.</p>' // Changed 'body' to 'content'
        ]);

        $this->actingAs($user);

        $memberRoutes = [
            'http://localhost/church/members',
            // Add other member-specific routes here
        ];

        foreach ($memberRoutes as $route) {
            $response = $this->get($route);
            $response->assertStatus(200);
        }
    }
}
