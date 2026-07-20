<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Sermon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthorizationGatesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function verified_admins_can_access_admin_routes(): void
    {
        $user = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        $this->assertTrue($user->canAccessAdmin());
    }

    #[Test]
    public function unverified_admins_cannot_access_admin_routes(): void
    {
        $user = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => null,
        ]);

        $this->assertFalse($user->canAccessAdmin());
    }

    #[Test]
    public function non_admin_users_cannot_access_admin_routes(): void
    {
        $user = User::factory()->create([
            'is_admin' => false,
            'email_verified_at' => now(),
        ]);

        $this->assertFalse($user->canAccessAdmin());
    }

    #[Test]
    public function sermon_policy_matches_the_admin_route_capability(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);
        $nonAdmin = User::factory()->create([
            'is_admin' => false,
            'email_verified_at' => now(),
        ]);

        $this->assertTrue($admin->can('viewAny', Sermon::class));
        $this->assertFalse($nonAdmin->can('viewAny', Sermon::class));
    }
}
