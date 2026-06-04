<?php

declare(strict_types=1);

namespace Tests\Integration\Models;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_can_be_created_via_factory(): void
    {
        $user = User::factory()->create();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ]);
        $this->assertFalse($user->is_admin);
    }

    #[Test]
    public function it_can_access_admin_only_if_admin_and_verified(): void
    {
        // Not admin, not verified
        $user = User::factory()->create([
            'is_admin' => false,
            'email_verified_at' => null,
        ]);
        $this->assertFalse($user->canAccessAdmin());

        // Admin, not verified
        $user->is_admin = true;
        $user->save();
        $this->assertFalse($user->canAccessAdmin());

        // Not admin, verified
        $user->is_admin = false;
        $user->email_verified_at = now();
        $user->save();
        $this->assertFalse($user->canAccessAdmin());

        // Admin and verified
        $user->is_admin = true;
        $user->save();
        $this->assertTrue($user->canAccessAdmin());
    }

    #[Test]
    public function is_admin_is_not_mass_assignable(): void
    {
        $user = new User([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'is_admin' => true,
        ]);

        // It should be null (since it's not fillable and not yet saved/defaulted in DB)
        // or false if we want to be strict, but mass assignment will definitely NOT set it to true.
        $this->assertNull($user->is_admin);
    }

    #[Test]
    public function it_has_expected_casts(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_admin' => true,
        ]);

        $this->assertInstanceOf(Carbon::class, $user->email_verified_at);
        $this->assertIsBool($user->is_admin);

        // Testing password cast (hashed)
        $this->assertTrue(Hash::check('password', $user->password));
    }

    #[Test]
    public function it_has_expected_fillable_attributes(): void
    {
        $user = new User;

        $this->assertEquals([
            'name',
            'email',
            'password',
        ], $user->getFillable());
    }
}
