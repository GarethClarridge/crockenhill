<?php

namespace Tests\Feature;

use App\Models\Sermon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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

        // Attempt to post upload as non-admin
        $response = $this->actingAs($user)->post('/admin/sermon-upload', [
            'type' => 'audio',
            'file' => UploadedFile::fake()->create('sermon.mp3', 100),
        ]);
        $response->assertStatus(403);
    }

    public function test_sermon_edit_route_requires_admin(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $sermon = Sermon::factory()->create(['date' => now()]);

        $response = $this->actingAs($user)->get("/christ/sermons/{$sermon->slug}/edit");
        $response->assertStatus(403);

        $response = $this->actingAs($user)->post("/christ/sermons/{$sermon->slug}/edit", []);
        $response->assertStatus(403);
    }

    public function test_sermon_upload_leaks_no_information_on_error(): void
    {
        Log::spy();
        Storage::fake('public');

        $admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);

        // We'll mock the processor to throw an exception with sensitive info
        $this->mock(\App\Services\UnifiedMediaProcessor::class, function ($mock) {
            $mock->shouldReceive('process')
                ->andThrow(new \Exception('Sensitive database error or path: /secret/path'));
        });

        $response = $this->actingAs($admin)->post('/admin/sermon-upload', [
            'type' => 'audio',
            'file' => UploadedFile::fake()->create('sermon.mp3', 100),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error', 'An error occurred during upload. Please try again or contact support.');

        Log::shouldHaveReceived('error')
            ->once()
            ->with('Sermon upload failed', \Mockery::on(function ($data) {
                return str_contains($data['error'], 'Sensitive database error');
            }));
    }
}
