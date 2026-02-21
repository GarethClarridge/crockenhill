<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaSecurityTest extends TestCase
{
    // No database traits

    public function test_non_admin_user_cannot_upload_media_via_api()
    {
        Storage::fake('public');

        $user = User::factory()->make(['id' => 1, 'is_admin' => false]);

        $file = UploadedFile::fake()->create('sermon.mp3', 100);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(route('api.media.upload', ['type' => 'audio']), [
                'file' => $file,
            ]);

        $response->assertStatus(403);
    }

    public function test_admin_user_can_upload_media_via_api()
    {
        Storage::fake('public');

        $user = User::factory()->make(['id' => 2, 'is_admin' => true]);

        $file = UploadedFile::fake()->create('sermon.mp3', 100);

        // Mock the media processor to avoid actual processing
        $this->mock(\App\Services\UnifiedMediaProcessor::class, function ($mock) {
            $mock->shouldReceive('process')->andReturn(
                \App\Services\ProcessingResult::success('test-uuid', 'Success')
            );
        });

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(route('api.media.upload', ['type' => 'audio']), [
                'file' => $file,
            ]);

        $response->assertStatus(202);
    }
}
