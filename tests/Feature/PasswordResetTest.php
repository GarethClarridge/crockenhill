<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User; // Assuming User model namespace
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test; // Added import

class PasswordResetTest extends TestCase
{
  use RefreshDatabase;

  #[Test] // Replaced @test
  public function password_request_page_can_be_rendered()
  {
    $response = $this->get(route('password.request'));
    $response->assertStatus(200);
    $response->assertSee('Email Address');
  }

  #[Test] // Replaced @test
  public function user_can_request_password_reset_link_with_valid_email()
  {
    $user = \App\Models\User::factory()->create();

    $response = $this->post(route('password.email'), ['email' => $user->email]);

    $response->assertRedirect(route('password.request')); // Or wherever it redirects with status
    $response->assertSessionHas('status', __('passwords.sent')); // Default status message

    $this->assertDatabaseHas('password_reset_tokens', [ // Changed table name
      'email' => $user->email,
    ]);

    // Clean up to avoid interference if not using RefreshDatabase per test method
    // DB::table('password_reset_tokens')->where('email', $user->email)->delete(); // Changed table name
  }

  #[Test] // Replaced @test
  public function user_cannot_request_password_reset_link_with_invalid_email()
  {
    $response = $this->post(route('password.email'), ['email' => 'nonexistent@example.com']);

    // $response->assertRedirect(route('password.request')); // Redirects back
    $response->assertSessionHasErrors('email', __('passwords.user'));
    $this->assertDatabaseMissing('password_reset_tokens', [ // Changed table name
      'email' => 'nonexistent@example.com',
    ]);
  }

  #[Test] // Replaced @test
  public function password_reset_form_can_be_rendered_with_valid_token()
  {
    $user = \App\Models\User::factory()->create();
    // Manually create a token for predictability in test, or extract after posting to password.email
    $token = Password::broker()->createToken($user);

    DB::table('password_reset_tokens')->insert([ // Changed table name
      'email' => $user->email,
      'token' => Hash::make($token), // Store hashed token if app hashes it
      'created_at' => now(),
    ]);
    // Note: Laravel 8+ stores hashed tokens. If not hashing, store $token directly.
    // For this test, we'll assume the app doesn't re-hash the token for the GET request URL.

    $response = $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]));

    $response->assertStatus(200);
    $response->assertSee('Email Address');
    $response->assertSee('Password');
    $response->assertSee('Confirm Password');
    $response->assertSee($user->email); // Email should be pre-filled
    $response->assertSeeHTML("<input type=\"hidden\" name=\"token\" value=\"{$token}\">");
  }

  #[Test] // Replaced @test
  public function user_can_reset_password_with_valid_token_and_data()
  {
    $user = \App\Models\User::factory()->create();
    $token = Password::broker()->createToken($user);
    DB::table('password_reset_tokens')->insert([ // Changed table name
      'email' => $user->email,
      'token' => Hash::make($token), // Assuming hashed tokens are stored from Laravel 8+
      'created_at' => now()
    ]);

    $newPassword = 'new-secure-password';
    $response = $this->post(route('password.update'), [
      'token' => $token,
      'email' => $user->email,
      'password' => $newPassword,
      'password_confirmation' => $newPassword,
    ]);

    // Default redirect is usually to login with a status message
    $response->assertRedirect(route('login'));
    $response->assertSessionHas('status', __('passwords.reset'));

    $this->assertTrue(Hash::check($newPassword, $user->fresh()->password));
    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]); // Changed table name

    // Test login with new password
    $loginResponse = $this->post(route('login'), ['email' => $user->email, 'password' => $newPassword]);
    $loginResponse->assertRedirect('/church/members'); // Or intended redirect
    $this->assertAuthenticatedAs($user);
  }

  #[Test] // Replaced @test
  public function user_cannot_reset_password_with_invalid_token()
  {
    $user = \App\Models\User::factory()->create();
    $newPassword = 'new-secure-password';

    $response = $this->post(route('password.update'), [
      'token' => 'invalid-token',
      'email' => $user->email,
      'password' => $newPassword,
      'password_confirmation' => $newPassword,
    ]);

    // $response->assertRedirect(route('password.request')); // Or back to the reset form
    $response->assertSessionHasErrors('email', __('passwords.token')); // Error tied to email or token
  }

  #[Test] // Replaced @test
  public function user_cannot_reset_password_with_mismatched_passwords()
  {
    $user = \App\Models\User::factory()->create();
    $token = Password::broker()->createToken($user);
    DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => Hash::make($token), 'created_at' => now()]); // Changed table name

    $response = $this->post(route('password.update'), [
      'token' => $token,
      'email' => $user->email,
      'password' => 'newpassword123',
      'password_confirmation' => 'anotherpassword456',
    ]);

    $response->assertSessionHasErrors('password', __('passwords.confirmed'));
    $this->assertTrue(Hash::check($user->password, $user->fresh()->password)); // Password should not change
  }

  #[Test] // Replaced @test
  public function user_cannot_reset_password_if_password_is_too_short()
  {
    $user = \App\Models\User::factory()->create();
    $token = Password::broker()->createToken($user);
    DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => Hash::make($token), 'created_at' => now()]); // Changed table name

    $shortPassword = Str::random(5); // Assuming min length is 8

    $response = $this->post(route('password.update'), [
      'token' => $token,
      'email' => $user->email,
      'password' => $shortPassword,
      'password_confirmation' => $shortPassword,
    ]);

    $response->assertSessionHasErrors('password'); // General message for length from validator
    $this->assertTrue(Hash::check($user->password, $user->fresh()->password)); // Password should not change
  }
}
