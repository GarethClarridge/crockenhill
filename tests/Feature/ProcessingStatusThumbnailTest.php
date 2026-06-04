<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ApiTokenAbility;
use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\User;
use App\Services\Sermon\SermonStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProcessingStatusThumbnailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock the storage disk
        Storage::fake('public');
    }

    public function test_processing_status_includes_thumbnail_information(): void
    {
        // Create a sermon with thumbnail
        $sermon = Sermon::factory()->create([
            'thumbnail_file_path' => 'sermons/thumbnails/test-thumbnail.jpg',
            'thumbnail_generated_at' => now(),
        ]);

        // Create a fake thumbnail file
        Storage::disk('public')->put('sermons/thumbnails/test-thumbnail.jpg', 'fake image content');

        // Create processing log with UUID format
        $processingId = '12345678-1234-1234-1234-123456789012';
        $processingLog = MediaProcessingLog::factory()->create([
            'sermon_id' => $sermon->id,
            'processing_id' => $processingId,
            'status' => ProcessingStatus::Completed,
        ]);

        // Create authenticated user
        $user = User::factory()->create(['is_admin' => true]);

        Sanctum::actingAs($user, [ApiTokenAbility::MEDIA_PROCESS->value]);

        $response = $this->getJson("/api/media/processing/{$processingId}/status");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'found',
                'processing_id',
                'status',
                'thumbnail_url',
                'thumbnail_generated',
                'thumbnail_generated_at',
            ])
            ->assertJson([
                'found' => true,
                'processing_id' => $processingId,
                'thumbnail_generated' => true,
                'thumbnail_url' => app(SermonStorageService::class)->getThumbnailUrl($sermon),
            ]);

        $this->assertNotNull($response->json('thumbnail_generated_at'));
    }

    public function test_processing_status_handles_missing_thumbnail(): void
    {
        // Create a sermon without thumbnail
        $sermon = Sermon::factory()->create([
            'thumbnail_file_path' => null,
        ]);

        // Create processing log with UUID format
        $processingId = '87654321-4321-4321-4321-210987654321';
        $processingLog = MediaProcessingLog::factory()->create([
            'sermon_id' => $sermon->id,
            'processing_id' => $processingId,
            'status' => ProcessingStatus::Completed,
        ]);

        // Create authenticated user
        $user = User::factory()->create(['is_admin' => true]);

        Sanctum::actingAs($user, [ApiTokenAbility::MEDIA_PROCESS->value]);

        $response = $this->getJson("/api/media/processing/{$processingId}/status");

        $response->assertStatus(200)
            ->assertJson([
                'found' => true,
                'processing_id' => $processingId,
                'thumbnail_generated' => false,
                'thumbnail_url' => null,
                'thumbnail_generated_at' => null,
            ]);
    }

    public function test_processing_status_handles_nonexistent_sermon(): void
    {
        // Create processing log with null sermon_id (allowed by foreign key constraint)
        $processingId = '11111111-2222-3333-4444-555555555555';
        $processingLog = MediaProcessingLog::factory()->create([
            'sermon_id' => null,
            'processing_id' => $processingId,
            'status' => ProcessingStatus::Completed,
        ]);

        // Create authenticated user
        $user = User::factory()->create(['is_admin' => true]);

        Sanctum::actingAs($user, [ApiTokenAbility::MEDIA_PROCESS->value]);

        $response = $this->getJson("/api/media/processing/{$processingId}/status");

        $response->assertStatus(200)
            ->assertJson([
                'found' => true,
                'processing_id' => $processingId,
            ]);

        // Should not have thumbnail data when sermon doesn't exist
        $this->assertArrayNotHasKey('thumbnail_url', $response->json());
        $this->assertArrayNotHasKey('thumbnail_generated', $response->json());
        $this->assertArrayNotHasKey('thumbnail_generated_at', $response->json());
    }
}
