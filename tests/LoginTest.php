<?php

namespace Tests;

// It's better to use RefreshDatabase for modern Laravel testing
// use Illuminate\Foundation\Testing\DatabaseTransactions;
// use Illuminate\Foundation\Testing\DatabaseMigrations; // Or this
use Illuminate\Foundation\Testing\RefreshDatabase; // Recommended
use Illuminate\Support\Facades\Auth;
use Crockenhill\User; // Assuming User model namespace

class LoginTest extends TestCase
{
  use RefreshDatabase; // Recommended trait

  /**
   * Test that unauthenticated users are redirected from /church/members to the login page.
   * @test
   * @return void
   */
  public function unauthenticated_user_is_redirected_from_members_area()
  {
    $response = $this->get('/church/members');

    $response->assertStatus(302);
    $response->assertRedirect(route('login'));
  }

  // testUserLoginForm seems incomplete or for a different purpose, skipping for this subtask

  /**
   * Test that a logged-in user can access the members area.
   * @test
   * @return void
   */
  public function authenticated_user_can_access_members_area()
  {
    // Ensure a user with ID 2 exists, or create one.
    // For robustness, let's create a user.
    $user = User::factory()->create(); // This user will get a random ID.
    // If ID 2 is specifically required by other parts of member area logic,
    // then ensure User ID 2 is created or use that specific user.
    // For just testing auth, any authenticated user should do.
    // $user = User::find(2) ?? User::factory()->create(['id' => 2]);
    // Auth::loginUsingId(2) can be problematic if DB is refreshed and ID 2 doesn't exist.

    // Using actingAs for cleaner authentication state management in tests
    $this->actingAs($user);

    $response = $this->get('/church/members');

    $response->assertStatus(200);
    $response->assertViewIs('members.home'); // Assuming this is the correct view
    $response->assertSee('Welcome to the members'); // Or other relevant content from members.home
  }

  /**
   * @test
   */
  public function user_can_login_with_valid_credentials()
  {
    $user = User::factory()->create(['password' => bcrypt($password = 'i-love-laravel')]);

    $response = $this->post(route('login'), [
      'email' => $user->email,
      'password' => $password,
    ]);

    $response->assertRedirect('/church/members'); // Or config('fortify.home') or other intended route
    $this->assertAuthenticatedAs($user);
  }

  /**
   * @test
   */
  public function user_cannot_login_with_invalid_email()
  {
    User::factory()->create(['email' => 'realuser@example.com', 'password' => bcrypt('password123')]);

    $response = $this->post(route('login'), [
      'email' => 'nonexistentuser@example.com',
      'password' => 'password123',
    ]);

    $response->assertRedirect(route('login')); // Should redirect back to login form
    $response->assertSessionHasErrors('email');
    $this->assertGuest();
  }

  /**
   * @test
   */
  public function user_cannot_login_with_invalid_password()
  {
    $user = User::factory()->create(['password' => bcrypt('real-password')]);

    $response = $this->post(route('login'), [
      'email' => $user->email,
      'password' => 'wrong-password',
    ]);

    $response->assertRedirect(route('login')); // Should redirect back to login form
    $response->assertSessionHasErrors('email'); // Laravel's default behavior
    $this->assertGuest();
  }

  /**
   * @test
   */
  public function authenticated_user_can_logout()
  {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->post(route('logout'));

    // Default redirect for logout is usually '/' or login page if guest middleware kicks in on intended.
    // Let's assume it redirects to home page or login page.
    // If there's a specific logout redirect, change this.
    $response->assertRedirect('/');
    $this->assertGuest();
  }
}
