<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\InboundEmailStatus;
use App\Enums\ProcessingStatus;
use App\Models\InboundEmail;
use App\Models\MediaProcessingLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MemberControllerTest extends TestCase
{
    use RefreshDatabase;

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

    #[Test]
    public function admin_dashboard_route_redirects_to_members_home(): void
    {
        $admin = User::factory()->admin()->create([
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin);
        $response = $this->get(route('admin.dashboard'));

        $response->assertRedirect('/church/members');
    }

    #[Test]
    public function members_home_shows_the_inbound_email_review_link_for_admins(): void
    {
        $admin = User::factory()->admin()->create([
            'email_verified_at' => now(),
        ]);

        InboundEmail::factory()->count(2)->create([
            'status' => InboundEmailStatus::Pending->value,
        ]);

        $this->actingAs($admin);
        $response = $this->get(route('memberHome'));

        $response->assertOk();
        $response->assertSee(route('admin.services.inbound-emails'));
        $response->assertSeeText('Review inbound emails');
        $response->assertSeeText('2');
    }

    #[Test]
    public function members_home_counts_legacy_livestream_manual_review_runs_for_admins(): void
    {
        $admin = User::factory()->admin()->create([
            'email_verified_at' => now(),
        ]);

        MediaProcessingLog::factory()->livestream()->create([
            'status' => ProcessingStatus::Failed,
            'current_step' => 'manual_review_required',
            'error_message' => 'Manual Review Note: Multiple speech blocks met the 20-minute sermon threshold.',
            'processing_metadata' => null,
        ]);

        $this->actingAs($admin);
        $response = $this->get(route('memberHome'));

        $response->assertOk();
        $response->assertSeeText('Sermon review');
        $response->assertSeeText('1');
    }

    #[Test]
    public function admin_dashboard_route_requires_authentication(): void
    {
        $response = $this->get(route('admin.dashboard'));

        $response->assertRedirect('/login');
    }
}
