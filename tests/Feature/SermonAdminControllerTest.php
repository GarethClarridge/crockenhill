<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PreacherSource;
use App\Models\Preacher;
use App\Models\PreacherAlias;
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

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->admin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        $this->user = User::factory()->create([
            'is_admin' => false,
            'email_verified_at' => now(),
        ]);
    }

    #[Test]
    public function admin_can_access_edit_page(): void
    {
        $sermon = Sermon::factory()->create();

        $response = $this->actingAs($this->admin)->get("/christ/sermons/{$sermon->slug}/edit");

        $response->assertStatus(200);
        $response->assertViewIs('sermons.edit');
        $response->assertViewHas('sermon');
    }

    #[Test]
    public function non_admin_cannot_access_edit_page(): void
    {
        $sermon = Sermon::factory()->create();

        $response = $this->actingAs($this->user)->get("/christ/sermons/{$sermon->slug}/edit");

        $response->assertStatus(403);
    }

    #[Test]
    public function admin_can_delete_sermon(): void
    {
        $sermon = Sermon::factory()->create();

        $response = $this->actingAs($this->admin)->post("/christ/sermons/{$sermon->slug}/delete");

        $response->assertRedirect(route('sermonIndex'));
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
    public function admin_can_access_upload_page(): void
    {
        $response = $this->actingAs($this->admin)->get('/church/members/sermon-upload');

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

        $response = $this->actingAs($this->admin)->post('/church/members/sermon-upload', [
            'file' => $file,
            'type' => 'audio',
        ]);

        $response->assertRedirect(route('sermonIndex'));
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

        $response = $this->actingAs($this->admin)->post('/church/members/sermon-upload', [
            'file' => $file,
            'type' => 'audio',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Failed to process');
    }

    #[Test]
    public function edit_with_date_works_when_date_matches(): void
    {
        $sermon = Sermon::factory()->create(['date' => '2024-03-15']);

        $response = $this->actingAs($this->admin)->get("/christ/sermons/2024/03/{$sermon->slug}/edit");

        $response->assertStatus(200);
    }

    #[Test]
    public function edit_with_date_returns_404_when_date_mismatches(): void
    {
        $sermon = Sermon::factory()->create(['date' => '2024-03-15']);

        // Wrong year
        $response = $this->actingAs($this->admin)->get("/christ/sermons/2023/03/{$sermon->slug}/edit");
        $response->assertStatus(404);

        // Wrong month
        $response = $this->actingAs($this->admin)->get("/christ/sermons/2024/04/{$sermon->slug}/edit");
        $response->assertStatus(404);
    }

    #[Test]
    public function update_with_date_works_when_date_matches(): void
    {
        $sermon = Sermon::factory()->create([
            'date' => '2024-03-15',
            'title' => 'Old Title',
            'slug' => 'old-title',
        ]);

        $response = $this->actingAs($this->admin)->post("/christ/sermons/2024/03/{$sermon->slug}/edit", [
            'title' => 'New Title',
            'date' => '2024-03-15',
            'service' => 'morning',
            'preacher' => 'Test Preacher',
        ]);

        $response->assertRedirect(route('sermonIndex'));
        $this->assertDatabaseHas('sermons', [
            'id' => $sermon->id,
            'title' => 'New Title',
        ]);
    }

    #[Test]
    public function update_with_date_returns_404_when_date_mismatches(): void
    {
        $sermon = Sermon::factory()->create(['date' => '2024-03-15']);

        $response = $this->actingAs($this->admin)->post("/christ/sermons/2023/03/{$sermon->slug}/edit", [
            'title' => 'New Title',
            'date' => '2024-03-15',
            'service' => 'morning',
            'preacher' => 'Test Preacher',
        ]);

        $response->assertStatus(404);
    }

    #[Test]
    public function destroy_with_date_works_when_date_matches(): void
    {
        $sermon = Sermon::factory()->create(['date' => '2024-03-15']);

        $response = $this->actingAs($this->admin)->post("/christ/sermons/2024/03/{$sermon->slug}/delete");

        $response->assertRedirect(route('sermonIndex'));
        $this->assertDatabaseMissing('sermons', ['id' => $sermon->id]);
    }

    #[Test]
    public function destroy_with_date_returns_404_when_date_mismatches(): void
    {
        $sermon = Sermon::factory()->create(['date' => '2024-03-15']);

        $response = $this->actingAs($this->admin)->post("/christ/sermons/2023/03/{$sermon->slug}/delete");

        $response->assertStatus(404);
        $this->assertDatabaseHas('sermons', ['id' => $sermon->id]);
    }

    #[Test]
    public function update_resolves_and_persists_canonical_preacher_fields(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Original Title',
            'slug' => 'original-title',
            'date' => '2024-03-15',
            'service' => 'morning',
            'preacher' => 'Legacy Name',
            'preacher_id' => null,
            'preacher_source' => null,
            'needs_preacher_review' => true,
        ]);

        $response = $this->actingAs($this->admin)->post("/christ/sermons/{$sermon->slug}/edit", [
            'title' => 'Updated Sermon Title',
            'date' => '2024-03-15',
            'service' => 'morning',
            'preacher' => '  New   Guest  ',
            'series' => 'Test Series',
            'reference' => 'John 3:16',
            'points' => json_encode(['Point 1', 'Point 2']),
            'summary' => 'Summary text',
            'show_summary' => '1',
            'show_points' => '1',
        ]);

        $response->assertRedirect(route('sermonIndex'));

        $sermon->refresh();

        $preacher = Preacher::where('slug', 'new-guest')->first();
        $this->assertNotNull($preacher);
        $this->assertEquals('New Guest', $preacher->name);

        $this->assertEquals($preacher->id, $sermon->preacher_id);
        $this->assertEquals('New Guest', $sermon->preacher);
        $this->assertEquals(PreacherSource::MANUAL, $sermon->preacher_source);
        $this->assertFalse($sermon->needs_preacher_review);

        $this->assertDatabaseHas('preacher_aliases', [
            'preacher_id' => $preacher->id,
            'alias' => 'new guest',
        ]);

        $alias = PreacherAlias::where('alias', 'new guest')->first();
        $this->assertNotNull($alias);
        $this->assertEquals($preacher->id, $alias->preacher_id);
    }
}
