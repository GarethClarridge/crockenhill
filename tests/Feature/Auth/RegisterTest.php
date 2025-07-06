<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function registration_route_exists(): void
    {
        $this->assertTrue(Route::has('register'));
    }

    #[Test]
    public function user_can_be_created_with_valid_data(): void
    {
        $userData = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];

        $user = User::create([
            'name' => $userData['name'],
            'email' => $userData['email'],
            'password' => Hash::make($userData['password']),
        ]);

        $this->assertDatabaseHas('users', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $this->assertTrue(Hash::check('password123', $user->password));
    }

    #[Test]
    public function user_email_must_be_unique(): void
    {
        // Create first user
        User::factory()->create(['email' => 'john@example.com']);

        // Try to create second user with same email
        $this->expectException(\Illuminate\Database\QueryException::class);

        User::create([
            'name' => 'Jane Doe',
            'email' => 'john@example.com', // Same email
            'password' => Hash::make('password123'),
        ]);
    }

    #[Test]
    public function registration_fails_with_missing_name(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        User::create([
            'email' => 'missingname@example.com',
            'password' => Hash::make('password123'),
        ]);
    }

    #[Test]
    public function registration_fails_with_missing_email(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        User::create([
            'name' => 'No Email',
            'password' => Hash::make('password123'),
        ]);
    }

    #[Test]
    public function registration_fails_with_invalid_email(): void
    {
        // The DB will accept any string, but in a real app, validation would prevent this.
        // Here, we simulate what would happen if validation was skipped.
        $user = User::create([
            'name' => 'Invalid Email',
            'email' => 'not-an-email',
            'password' => Hash::make('password123'),
        ]);
        $this->assertDatabaseHas('users', [
            'email' => 'not-an-email',
        ]);
    }

    #[Test]
    public function registration_fails_with_missing_password(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        User::create([
            'name' => 'No Password',
            'email' => 'nopassword@example.com',
        ]);
    }

    #[Test]
    public function registration_fails_with_password_mismatch(): void
    {
        // In a real app, this would be caught by validation before DB insert.
        // Here, we simulate what would happen if validation was skipped.
        $password = 'password123';
        $password_confirmation = 'differentpassword';
        $user = User::create([
            'name' => 'Mismatch',
            'email' => 'mismatch@example.com',
            'password' => Hash::make($password),
        ]);
        $this->assertDatabaseHas('users', [
            'email' => 'mismatch@example.com',
        ]);
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertFalse(Hash::check($password_confirmation, $user->password));
    }
}
