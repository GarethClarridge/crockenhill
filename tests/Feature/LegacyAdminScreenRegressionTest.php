<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sermon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LegacyAdminScreenRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);
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
