<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sermon;
use App\Models\User;
use App\Services\Processing\UnifiedMediaProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SentinelSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_sermon_upload_route_requires_admin(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        // Attempt to access upload page as non-admin
        $response = $this->actingAs($user)->get('/admin/sermon-upload');
        $response->assertStatus(403);

        // Attempt to upload via the media API as non-admin — the media.process
        // middleware denies anyone without admin access.
        Sanctum::actingAs($user, ['*']);
        $response = $this->postJson('/api/media/audio', [
            'file' => UploadedFile::fake()->create('sermon.mp3', 100),
        ]);
        $response->assertStatus(403);
    }

    public function test_sermon_edit_route_requires_admin(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $sermon = Sermon::factory()->create(['date' => now()]);

        // Legacy /edit route is fully removed — no route matches, returns 404
        $response = $this->actingAs($user)->get("/christ/sermons/{$sermon->slug}/edit");
        $response->assertStatus(404);

        $response = $this->actingAs($user)->post("/christ/sermons/{$sermon->slug}/edit", []);
        $response->assertStatus(404);

        // The canonical admin edit route still enforces admin access
        $response = $this->actingAs($user)->get(route('admin.sermons.edit', $sermon->slug));
        $response->assertStatus(403);
    }

    public function test_sermon_upload_leaks_no_information_on_error(): void
    {
        Log::spy();
        Storage::fake('public');

        $admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);

        // We'll mock the processor to throw an exception with sensitive info
        $this->mock(UnifiedMediaProcessor::class, function ($mock) {
            $mock->shouldReceive('process')
                ->andThrow(new \Exception('Sensitive database error or path: /secret/path'));
        });

        Sanctum::actingAs($admin, ['*']);
        $response = $this->postJson('/api/media/audio', [
            'file' => UploadedFile::fake()->create('sermon.mp3', 100),
        ]);

        // The API returns a generic 500 payload — the sensitive exception text must
        // never reach the client, only the sanitized server log.
        $response->assertStatus(500);
        $response->assertExactJson([
            'success' => false,
            'message' => 'Media upload failed',
            'error_code' => 'UPLOAD_FAILED',
        ]);
        $this->assertStringNotContainsString('/secret/path', (string) $response->getContent());

        Log::shouldHaveReceived('error')
            ->once()
            ->with('Media upload failed', \Mockery::on(function ($data) {
                return str_contains($data['error'], 'Sensitive database error');
            }));
    }
}
