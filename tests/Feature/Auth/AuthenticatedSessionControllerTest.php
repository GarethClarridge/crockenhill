<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthenticatedSessionControllerTest extends TestCase
{
    use DatabaseTransactions;

    // ── logout ────────────────────────────────────────────────────────────

    #[Test]
    public function authenticated_user_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $response->assertRedirect('/');
        $this->assertGuest();
    }

    #[Test]
    public function guest_post_to_logout_redirects_to_home(): void
    {
        // Unauthenticated logout should not crash — it just redirects.
        $response = $this->post('/logout');

        $response->assertRedirect('/');
    }

    #[Test]
    public function session_is_invalidated_after_logout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout');

        $this->assertGuest();
    }
}
