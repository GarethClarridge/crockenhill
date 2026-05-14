<?php

declare(strict_types=1);

namespace Tests\Integration\Policies;

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
        $this->assertTrue($user->can('create', Sermon::class));
    }

    #[Test]
    public function it_denies_unverified_admins(): void
    {
        $user = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => null,
        ]);
        $sermon = Sermon::factory()->create();

        $this->assertFalse($user->can('viewAny', Sermon::class));
        $this->assertFalse($user->can('create', Sermon::class));
        $this->assertFalse($user->can('update', $sermon));
        $this->assertFalse($user->can('delete', $sermon));
    }

    #[Test]
    public function it_denies_non_admin_users_regardless_of_email_domain(): void
    {
        $regularUser = User::factory()->create([
            'email' => 'regular@gmail.com',
            'is_admin' => false,
            'email_verified_at' => now(),
        ]);

        $crockenhillUser = User::factory()->create([
            'email' => 'staff@crockenhill.org',
            'is_admin' => false,
            'email_verified_at' => now(),
        ]);

        $this->assertFalse($regularUser->can('create', Sermon::class));
        $this->assertFalse($crockenhillUser->can('create', Sermon::class));
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

    #[Test]
    public function it_denies_non_admin_users_from_modifying_sermons(): void
    {
        $user = User::factory()->create([
            'is_admin' => false,
            'email_verified_at' => now(),
        ]);
        $sermon = Sermon::factory()->create();

        $this->assertFalse($user->can('view', $sermon));
        $this->assertFalse($user->can('update', $sermon));
        $this->assertFalse($user->can('delete', $sermon));
    }
}
