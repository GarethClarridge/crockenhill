<?php

declare(strict_types=1);

namespace Tests\Feature\Health;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Health\ResultStores\ResultStore;
use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Runs the health checks with outbound canary HTTP faked to healthy
     * responses, storing results for the /health page to render.
     */
    private function runHealthChecks(): void
    {
        Http::fake([
            '*/sitemap.xml' => Http::response('<urlset></urlset>', 200),
            '*/church/members' => Http::response('', 302),
            '*' => Http::response('<html><body>Crockenhill Baptist Church</body></html>', 200),
        ]);

        $this->artisan('health:check', ['--no-notification' => true])->run();
    }

    #[Test]
    public function guest_is_redirected_to_login(): void
    {
        $this->get('/health')->assertRedirect('/login');
    }

    #[Test]
    public function non_admin_user_cannot_view_health_dashboard(): void
    {
        $user = User::factory()->create([
            'is_admin' => false,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)->get('/health')->assertForbidden();
    }

    #[Test]
    public function verified_admin_sees_every_registered_check(): void
    {
        $this->runHealthChecks();

        $admin = User::factory()->crockenhillAdmin()->create();

        $response = $this->actingAs($admin)->get('/health');

        $response->assertOk();

        foreach ([
            'Database',
            'Redis',
            'Redis Memory Usage',
            'Cache',
            'Horizon',
            'Temp Disk Space',
            'Scheduled Tasks',
            'Schedule',
            'Route Canaries',
        ] as $label) {
            $response->assertSeeText($label);
        }
    }

    #[Test]
    public function the_schedule_check_reports_ok_when_the_heartbeat_is_fresh(): void
    {
        cache()->put('health:checks:schedule:latestHeartbeatAt', now()->getTimestamp());

        $this->runHealthChecks();

        $results = app(ResultStore::class)->latestResults();
        $scheduleResult = collect($results?->storedCheckResults ?? [])->firstWhere('name', 'Schedule');

        $this->assertNotNull($scheduleResult, 'The schedule check should be registered and stored');
        $this->assertSame('ok', $scheduleResult->status);
    }

    #[Test]
    public function the_schedule_check_fails_when_the_heartbeat_is_stale(): void
    {
        cache()->put('health:checks:schedule:latestHeartbeatAt', now()->subMinutes(10)->getTimestamp());

        $this->runHealthChecks();

        $results = app(ResultStore::class)->latestResults();
        $scheduleResult = collect($results?->storedCheckResults ?? [])->firstWhere('name', 'Schedule');

        $this->assertNotNull($scheduleResult, 'The schedule check should be registered and stored');
        $this->assertSame('failed', $scheduleResult->status);
    }

    #[Test]
    public function an_over_threshold_temp_disk_marks_the_result_unhealthy(): void
    {
        // A floor far beyond any real disk guarantees free space sits below it.
        config(['media-processing.storage.temp_disk_min_free_gb' => 1_000_000]);

        $this->runHealthChecks();

        $results = app(ResultStore::class)->latestResults();
        $diskResult = collect($results?->storedCheckResults ?? [])->firstWhere('name', 'TempDiskSpace');

        $this->assertNotNull($diskResult, 'The temp disk check should be registered and stored');
        $this->assertSame('failed', $diskResult->status);

        $admin = User::factory()->crockenhillAdmin()->create();

        $this->actingAs($admin)
            ->get('/health')
            ->assertOk()
            ->assertSeeText('media uploads are being rejected');
    }
}
