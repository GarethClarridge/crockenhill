<?php

declare(strict_types=1);

namespace Tests\Integration\Policies;

use App\Models\Meeting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeetingPolicyTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function admin_with_verified_email_can_manage_meetings(): void
    {
        $user = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);
        $meeting = Meeting::factory()->create();

        $this->assertTrue($user->can('viewAny', Meeting::class));
        $this->assertTrue($user->can('view', $meeting));
        $this->assertTrue($user->can('create', Meeting::class));
        $this->assertTrue($user->can('update', $meeting));
        $this->assertTrue($user->can('delete', $meeting));
        $this->assertTrue($user->can('restore', $meeting));
        $this->assertTrue($user->can('forceDelete', $meeting));
    }

    #[Test]
    public function unverified_admin_cannot_manage_meetings(): void
    {
        $user = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => null,
        ]);
        $meeting = Meeting::factory()->create();

        $this->assertFalse($user->can('viewAny', Meeting::class));
        $this->assertFalse($user->can('view', $meeting));
        $this->assertFalse($user->can('create', Meeting::class));
        $this->assertFalse($user->can('update', $meeting));
        $this->assertFalse($user->can('delete', $meeting));
    }

    #[Test]
    public function non_admin_cannot_manage_meetings(): void
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

        $meeting = Meeting::factory()->create();

        $this->assertFalse($regularUser->can('viewAny', Meeting::class));
        $this->assertFalse($crockenhillUser->can('viewAny', Meeting::class));
        $this->assertFalse($regularUser->can('view', $meeting));
        $this->assertFalse($crockenhillUser->can('view', $meeting));

        $this->assertFalse($regularUser->can('create', Meeting::class));
        $this->assertFalse($crockenhillUser->can('create', Meeting::class));

        $this->assertFalse($regularUser->can('update', $meeting));
        $this->assertFalse($crockenhillUser->can('update', $meeting));

        $this->assertFalse($regularUser->can('delete', $meeting));
        $this->assertFalse($crockenhillUser->can('delete', $meeting));
    }
}
