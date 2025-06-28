<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User; // Corrected namespace
// UserFactory not explicitly used with Model::factory()
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon; // For date casting tests
use PHPUnit\Framework\Attributes\Test; // Added import

class UserTest extends TestCase
{
  use RefreshDatabase;

  #[Test] // Replaced @test
  public function testUserRelationships()
  {
    // No explicit relationships found on the User model (e.g., hasMany Pages/Sermons).
    // This test remains a placeholder. If relationships are added, tests should be implemented here.
    $user = \App\Models\User::factory()->create();
    $this->assertInstanceOf(\App\Models\User::class, $user);
    $this->assertTrue(true);
  }

  #[Test] // Replaced @test
  public function testUserAccessors()
  {
    // Test is_admin attribute/accessor
    $adminUser = \App\Models\User::factory()->admin()->create(); // Sets is_admin to true
    $this->assertTrue($adminUser->is_admin);

    $nonAdminUser = \App\Models\User::factory()->create(['is_admin' => false]); // Explicitly set is_admin to false
    $this->assertFalse($nonAdminUser->is_admin);

    // If an accessor like getIsAdminAttribute() is expected to exist and provide $user->is_admin
    // then the User model would need:
    // public function getIsAdminAttribute() { return $this->attributes['is_admin']; }
    // And the test would be:
    // $this->assertTrue($adminUser->is_admin);
    // $this->assertFalse($nonAdminUser->is_admin);
    // For now, testing the direct attribute based on factory and common usage.
  }

  #[Test] // Replaced @test
  public function testUserMutatorsAndCasts()
  {
    // Test password hashing
    $password = 'mySecretPassword123';
    $user = \App\Models\User::factory()->create(['password' => $password]);

    // Check that the password attribute in the database is not plain text
    // Note: User model's $hidden typically includes 'password', so direct access might be null
    // We fetch a fresh instance or use getAttribute to bypass $hidden for this test if needed.
    $rawUser = \App\Models\User::find($user->id);
    $this->assertNotNull($rawUser->getAttributes()['password']);
    $this->assertNotEquals($password, $rawUser->getAttributes()['password']);
    $this->assertTrue(Hash::check($password, $rawUser->password)); // password accessor should still work for Hash::check

    // Test with factory default password
    $userWithFactoryPassword = \App\Models\User::factory()->create();
    $this->assertTrue(Hash::check('password', $userWithFactoryPassword->password)); // 'password' is the default in factory

    // email_verified_at column does not exist in the users table,
    // so no casting test for it is applicable.
    // $userWithEmailVerified = \App\Models\User::factory()->create();
    // $this->assertInstanceOf(Carbon::class, $userWithEmailVerified->email_verified_at);

    // Test that if 'email_verified_at' is passed as null (e.g. during factory creation with state), it remains null
    // or more accurately, that the attribute isn't set to something unexpected.
    // Since the column doesn't exist, this test is more about factory behavior if such a state were called.
    // For now, we ensure UserFactory does not attempt to set it.
    $userNoEmailVerified = \App\Models\User::factory()->create(); // Factory no longer sets email_verified_at
    $this->assertNull($userNoEmailVerified->email_verified_at); // Accessing a non-existent attribute typically returns null
  }

  #[Test] // Replaced @test
  public function testCustomUserMethods()
  {
    // No specific custom methods (e.g., hasRole(), hasPermissionTo()) found on the User model
    // beyond the handling of 'is_admin_for_test' which is tested via accessor.
    // This test remains a placeholder.
    $user = \App\Models\User::factory()->create();
    $this->assertInstanceOf(\App\Models\User::class, $user);
    $this->assertTrue(true);
  }
}
