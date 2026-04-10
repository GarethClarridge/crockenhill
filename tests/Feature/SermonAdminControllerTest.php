<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sermon;
use App\Models\User;
use App\Services\ProcessingResult;
use App\Services\UnifiedMediaProcessor;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonAdminControllerTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private User $unverifiedAdmin;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);

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
    public function admin_can_access_upload_page(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/sermon-upload');

        $response->assertStatus(200);
        $response->assertViewIs('sermons.upload');
    }

    #[Test]
    public function admin_can_process_media_upload(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->create('sermon.mp3', 100);

        $mockProcessor = Mockery::mock(UnifiedMediaProcessor::class);
        $mockProcessor->shouldReceive('process')
            ->once()
            ->with('audio', Mockery::type(UploadedFile::class))
            ->andReturn(ProcessingResult::success('proc-123', 'Processing started'));

        $this->app->instance(UnifiedMediaProcessor::class, $mockProcessor);

        $response = $this->actingAs($this->admin)->post('/admin/sermon-upload', [
            'file' => $file,
            'type' => 'audio',
        ]);

        $response->assertRedirect(route('sermons.index'));
        $response->assertSessionHas('message', 'Processing started for "sermon.mp3". Processing ID: proc-123');
    }

    #[Test]
    public function process_media_upload_handles_failure(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->create('sermon.mp3', 100);

        $mockProcessor = Mockery::mock(UnifiedMediaProcessor::class);
        $mockProcessor->shouldReceive('process')
            ->once()
            ->andReturn(ProcessingResult::failure('proc-123', 'Failed to process', 'ERR_CODE'));

        $this->app->instance(UnifiedMediaProcessor::class, $mockProcessor);

        $response = $this->actingAs($this->admin)->post('/admin/sermon-upload', [
            'file' => $file,
            'type' => 'audio',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Failed to process');
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
