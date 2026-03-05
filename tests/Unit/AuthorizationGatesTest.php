<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthorizationGatesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function manage_sermons_gate_allows_verified_admins(): void
    {
        $user = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        $this->assertTrue($user->can('manage-sermons'));
    }

    #[Test]
    public function manage_sermons_gate_denies_unverified_admins(): void
    {
        $user = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => null,
        ]);

        $this->assertFalse($user->can('manage-sermons'));
    }

    #[Test]
    public function manage_sermons_gate_denies_non_admin_users(): void
    {
        $user = User::factory()->create([
            'is_admin' => false,
            'email_verified_at' => now(),
        ]);

        $this->assertFalse($user->can('manage-sermons'));
    }

    #[Test]
    public function manage_sermons_gate_denies_non_admin_crockenhill_domain_users(): void
    {
        $user = User::factory()->create([
            'email' => 'staff@crockenhill.org',
            'is_admin' => false,
            'email_verified_at' => now(),
        ]);

        $this->assertFalse($user->can('manage-sermons'));
    }

    #[Test]
    public function manage_meetings_gate_allows_verified_admins(): void
    {
        $user = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        $this->assertTrue($user->can('manage-meetings'));
    }

    #[Test]
    public function manage_meetings_gate_denies_unverified_admins(): void
    {
        $user = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => null,
        ]);

        $this->assertFalse($user->can('manage-meetings'));
    }

    #[Test]
    public function manage_meetings_gate_denies_non_admin_users(): void
    {
        $user = User::factory()->create([
            'is_admin' => false,
            'email_verified_at' => now(),
        ]);

        $this->assertFalse($user->can('manage-meetings'));
    }

    #[Test]
    public function manage_pages_gate_allows_verified_admins(): void
    {
        $user = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        $this->assertTrue($user->can('manage-pages'));
    }

    #[Test]
    public function manage_pages_gate_denies_unverified_admins(): void
    {
        $user = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => null,
        ]);

        $this->assertFalse($user->can('manage-pages'));
    }

    #[Test]
    public function manage_pages_gate_denies_non_admin_users(): void
    {
        $user = User::factory()->create([
            'is_admin' => false,
            'email_verified_at' => now(),
        ]);

        $this->assertFalse($user->can('manage-pages'));
    }
}
