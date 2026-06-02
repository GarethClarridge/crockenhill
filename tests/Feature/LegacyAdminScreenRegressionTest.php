<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sermon;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Route regression tests for the four legacy admin screens being migrated in TD-036.
 * These assert the routes still respond correctly before and after the Blade migration.
 */
class LegacyAdminScreenRegressionTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        $this->regularUser = User::factory()->create([
            'is_admin' => false,
            'email_verified_at' => now(),
        ]);
    }

    // --- admin/calendar/uncategorized ---

    #[Test]
    public function calendar_uncategorized_requires_authentication(): void
    {
        $response = $this->get('/admin/calendar/uncategorized');

        $response->assertRedirect('/login');
    }

    #[Test]
    public function calendar_uncategorized_forbids_non_admin(): void
    {
        $response = $this->actingAs($this->regularUser)->get('/admin/calendar/uncategorized');

        $response->assertForbidden();
    }

    #[Test]
    public function calendar_uncategorized_loads_for_admin(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/calendar/uncategorized');

        $response->assertOk();
        $response->assertViewIs('admin.calendar.uncategorized');
    }

    // --- admin/calendar/patterns ---

    #[Test]
    public function calendar_patterns_requires_authentication(): void
    {
        $response = $this->get('/admin/calendar/patterns');

        $response->assertRedirect('/login');
    }

    #[Test]
    public function calendar_patterns_forbids_non_admin(): void
    {
        $response = $this->actingAs($this->regularUser)->get('/admin/calendar/patterns');

        $response->assertForbidden();
    }

    #[Test]
    public function calendar_patterns_loads_for_admin(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/calendar/patterns');

        $response->assertOk();
        $response->assertViewIs('admin.calendar.patterns');
    }

    // --- sermons/edit ---

    #[Test]
    public function legacy_sermon_edit_with_date_route_is_removed(): void
    {
        $sermon = Sermon::factory()->create(['date' => '2024-03-15']);

        // Legacy date-based GET edit route removed — unauthenticated gets 404, not login redirect
        $response = $this->get("/christ/sermons/2024/03/{$sermon->slug}/edit");

        $response->assertStatus(404);
    }

    #[Test]
    public function admin_sermons_edit_route_is_the_only_sermon_edit_surface(): void
    {
        $sermon = Sermon::factory()->create();

        $response = $this->actingAs($this->admin)->get(route('admin.sermons.edit', $sermon->slug));

        $response->assertOk();
    }
}
