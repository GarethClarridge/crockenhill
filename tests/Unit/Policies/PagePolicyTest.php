<?php

namespace Tests\Unit\Policies;

use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PagePolicyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_allows_admins_with_verified_emails(): void
    {
        $user = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        $this->assertTrue($user->can('viewAny', Page::class));
    }

    #[Test]
    public function it_allows_users_with_crockenhill_domain(): void
    {
        $user = User::factory()->create([
            'email' => 'staff@crockenhill.org',
            'is_admin' => false,
            'email_verified_at' => now(),
        ]);

        $this->assertTrue($user->can('create', Page::class));
    }

    #[Test]
    public function it_denies_regular_users(): void
    {
        $user = User::factory()->create([
            'email' => 'regular@gmail.com',
            'is_admin' => false,
            'email_verified_at' => now(),
        ]);

        $this->assertFalse($user->can('create', Page::class));
    }

    #[Test]
    public function it_allows_viewing_specific_page_for_admins(): void
    {
        $user = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);
        $page = Page::factory()->create();

        $this->assertTrue($user->can('view', $page));
        $this->assertTrue($user->can('update', $page));
        $this->assertTrue($user->can('delete', $page));
    }
}
