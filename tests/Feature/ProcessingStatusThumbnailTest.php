<?php

namespace Tests\Feature;

use App\Models\Sermon;
use App\Models\SermonProcessingLog;
use App\Enums\ProcessingStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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
            'thumbnail_path' => 'sermons/thumbnails/test-thumbnail.jpg',
            'thumbnail_generated_at' => now(),
        ]);

        // Create a fake thumbnail file
        Storage::disk('public')->put('sermons/thumbnails/test-thumbnail.jpg', 'fake image content');

        // Create processing log with UUID format
        $processingId = '12345678-1234-1234-1234-123456789012';
        $processingLog = SermonProcessingLog::factory()->create([
            'sermon_id' => $sermon->id,
            'processing_id' => $processingId,
            'status' => ProcessingStatus::COMPLETED,
        ]);

        // Create authenticated user
        $user = \App\Models\User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/sermons/processing/{$processingId}/status");

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
                'thumbnail_url' => Storage::disk('public')->url('sermons/thumbnails/test-thumbnail.jpg'),
            ]);

        $this->assertNotNull($response->json('thumbnail_generated_at'));
    }

    public function test_processing_status_handles_missing_thumbnail(): void
    {
        // Create a sermon without thumbnail
        $sermon = Sermon::factory()->create([
            'thumbnail_path' => null,
        ]);

        // Create processing log with UUID format
        $processingId = '87654321-4321-4321-4321-210987654321';
        $processingLog = SermonProcessingLog::factory()->create([
            'sermon_id' => $sermon->id,
            'processing_id' => $processingId,
            'status' => ProcessingStatus::COMPLETED,
        ]);

        // Create authenticated user
        $user = \App\Models\User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/sermons/processing/{$processingId}/status");

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
        $processingLog = SermonProcessingLog::factory()->create([
            'sermon_id' => null,
            'processing_id' => $processingId,
            'status' => ProcessingStatus::COMPLETED,
        ]);

        // Create authenticated user
        $user = \App\Models\User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/sermons/processing/{$processingId}/status");

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