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
        $response = $this->get(route('members.home'));

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
    public function members_home_shows_the_review_inbox_link_with_an_attention_badge(): void
    {
        $admin = User::factory()->admin()->create([
            'email_verified_at' => now(),
        ]);

        InboundEmail::factory()->count(2)->create([
            'status' => InboundEmailStatus::Pending->value,
        ]);

        $this->actingAs($admin);
        $response = $this->get(route('members.home'));

        $response->assertOk();
        $response->assertSee(route('admin.services.add'));
        $response->assertSeeText('Add to service');
        $response->assertSee(route('admin.services.index'));
        $response->assertSeeText('Needs attention');
        $response->assertSeeText('2');
    }

    #[Test]
    public function review_inbox_badge_counts_legacy_livestream_manual_review_runs(): void
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
        $response = $this->get(route('members.home'));

        $response->assertOk();
        $response->assertSeeText('Needs attention');
        $response->assertSeeText('1');
    }

    #[Test]
    public function members_home_hides_service_buttons_but_keeps_uploads_when_service_tracking_is_disabled(): void
    {
        config(['service-tracking.enabled' => false]);

        $admin = User::factory()->admin()->create([
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin);
        $response = $this->get(route('members.home'));

        $response->assertOk();
        $response->assertDontSeeText('Needs attention');
        // Exact-URL check: the upload button's /admin/services/upload-recording
        // URL shares this prefix but is gated by the Sermon create Gate, not
        // service tracking, so it must stay.
        $response->assertDontSee(route('admin.services.index').'"', false);
        $response->assertSeeText('Upload sermon');
        $response->assertSee(route('admin.services.upload-recording'));
        $response->assertSeeText('Manage sermons');
    }

    #[Test]
    public function admin_dashboard_route_requires_authentication(): void
    {
        $response = $this->get(route('admin.dashboard'));

        $response->assertRedirect('/login');
    }
}
