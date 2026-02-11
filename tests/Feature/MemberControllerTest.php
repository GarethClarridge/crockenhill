<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MemberControllerTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_redirects_unauthenticated_users_to_login(): void
    {
        $response = $this->get('/church/members');

        $response->assertRedirect('/login');
    }

    #[Test]
    public function it_shows_members_home_for_authenticated_user(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);
        $response = $this->get('/church/members');

        $response->assertStatus(200);
        $response->assertViewIs('members.home');
    }

    #[Test]
    public function it_uses_the_member_home_named_route(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);
        $response = $this->get(route('memberHome'));

        $response->assertStatus(200);
    }

    #[Test]
    public function it_returns_200_not_a_redirect_for_authenticated_user(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);
        $response = $this->get('/church/members');

        $response->assertStatus(200);
        $response->assertDontSeeText('Redirecting');
    }
}
