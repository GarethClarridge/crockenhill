<?php

namespace Tests\Unit\Policies;

use App\Models\Sermon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonPolicyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_allows_admins_with_verified_emails(): void
    {
        $user = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        $this->assertTrue($user->can('viewAny', Sermon::class));
    }

    #[Test]
    public function it_denies_admins_with_unverified_emails(): void
    {
        $user = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => null,
        ]);

        $this->assertFalse($user->can('viewAny', Sermon::class));
    }

    #[Test]
    public function it_allows_users_with_crockenhill_domain(): void
    {
        $user = User::factory()->create([
            'email' => 'staff@crockenhill.org',
            'is_admin' => false,
            'email_verified_at' => now(),
        ]);

        $this->assertTrue($user->can('create', Sermon::class));
    }

    #[Test]
    public function it_denies_regular_users(): void
    {
        $user = User::factory()->create([
            'email' => 'regular@gmail.com',
            'is_admin' => false,
            'email_verified_at' => now(),
        ]);

        $this->assertFalse($user->can('create', Sermon::class));
    }

    #[Test]
    public function it_allows_viewing_specific_sermon_for_admins(): void
    {
        $user = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);
        $sermon = Sermon::factory()->create();

        $this->assertTrue($user->can('view', $sermon));
        $this->assertTrue($user->can('update', $sermon));
        $this->assertTrue($user->can('delete', $sermon));
    }
}
