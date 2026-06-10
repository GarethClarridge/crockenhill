<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HorizonDashboardTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function guest_cannot_view_horizon_dashboard(): void
    {
        $this->get('/horizon')->assertForbidden();
    }

    #[Test]
    public function non_admin_user_cannot_view_horizon_dashboard(): void
    {
        $user = User::factory()->create([
            'is_admin' => false,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)->get('/horizon')->assertForbidden();
    }

    #[Test]
    public function unverified_admin_cannot_view_horizon_dashboard(): void
    {
        $unverifiedAdmin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => null,
        ]);

        $this->actingAs($unverifiedAdmin)->get('/horizon')->assertForbidden();
    }

    #[Test]
    public function verified_admin_can_view_horizon_dashboard(): void
    {
        $admin = User::factory()->crockenhillAdmin()->create();

        $this->actingAs($admin)->get('/horizon')->assertOk();
    }
}
