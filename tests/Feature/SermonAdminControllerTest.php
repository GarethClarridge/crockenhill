<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Admin\MediaUpload;
use App\Models\Sermon;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonAdminControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $unverifiedAdmin;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);

        $this->admin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        $this->unverifiedAdmin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => null,
        ]);

        $this->user = User::factory()->create([
            'is_admin' => false,
            'email_verified_at' => now(),
        ]);
    }

    #[Test]
    public function legacy_slug_edit_get_route_is_removed(): void
    {
        $sermon = Sermon::factory()->create();

        // Legacy GET edit route removed — no handler, no redirect
        $response = $this->actingAs($this->admin)->get("/christ/sermons/{$sermon->slug}/edit");

        $response->assertStatus(404);
    }

    #[Test]
    public function admin_can_delete_sermon(): void
    {
        $sermon = Sermon::factory()->create();

        $response = $this->actingAs($this->admin)->post("/christ/sermons/{$sermon->slug}/delete");

        $response->assertRedirect(route('sermons.index'));
        $this->assertDatabaseMissing('sermons', ['id' => $sermon->id]);
    }

    #[Test]
    public function non_admin_cannot_delete_sermon(): void
    {
        $sermon = Sermon::factory()->create();

        $response = $this->actingAs($this->user)->post("/christ/sermons/{$sermon->slug}/delete");

        $response->assertStatus(403);
        $this->assertDatabaseHas('sermons', ['id' => $sermon->id]);
    }

    #[Test]
    public function unverified_admin_cannot_delete_sermon(): void
    {
        $sermon = Sermon::factory()->create();

        $response = $this->actingAs($this->unverifiedAdmin)
            ->post("/christ/sermons/{$sermon->slug}/delete");

        $response->assertRedirect(route('verification.notice'));
        $this->assertDatabaseHas('sermons', ['id' => $sermon->id]);
    }

    #[Test]
    public function legacy_upload_url_redirects_to_the_services_upload_recording_page(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/sermon-upload')
            ->assertRedirect('/admin/services/upload-recording');

        $this->actingAs($this->admin)
            ->get(route('admin.services.upload-recording'))
            ->assertStatus(200)
            ->assertSeeLivewire(MediaUpload::class);
    }

    #[Test]
    public function legacy_date_edit_get_route_is_removed(): void
    {
        $sermon = Sermon::factory()->create(['date' => '2024-03-15']);

        // Legacy date-based GET edit route removed — no handler, no redirect
        $response = $this->actingAs($this->admin)->get("/christ/sermons/2024/03/{$sermon->slug}/edit");

        $response->assertStatus(404);
    }

    #[Test]
    public function destroy_works_via_slug_route(): void
    {
        $sermon = Sermon::factory()->create(['date' => '2024-03-15']);

        $response = $this->actingAs($this->admin)->post("/christ/sermons/{$sermon->slug}/delete");

        $response->assertRedirect(route('sermons.index'));
        $this->assertDatabaseMissing('sermons', ['id' => $sermon->id]);
    }

    #[Test]
    public function dated_delete_url_returns_404_after_route_consolidation(): void
    {
        $sermon = Sermon::factory()->create(['date' => '2024-03-15']);

        $response = $this->actingAs($this->admin)->post("/christ/sermons/2024/03/{$sermon->slug}/delete");

        $response->assertStatus(404);
        $this->assertDatabaseHas('sermons', ['id' => $sermon->id]);
    }

    #[Test]
    public function legacy_post_update_route_is_removed(): void
    {
        $sermon = Sermon::factory()->create();

        // The legacy edit route is fully removed — no route exists for this URL at all
        $response = $this->actingAs($this->admin)->post("/christ/sermons/{$sermon->slug}/edit", [
            'title' => 'New Title',
            'date' => '2024-03-15',
            'service' => 'morning',
            'preacher' => 'Test Preacher',
        ]);

        $response->assertStatus(404);
    }
}
