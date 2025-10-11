<?php

namespace Tests\Integration\EndToEnd;

use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\SermonProcessingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Integration\BaseIntegrationTest;

class CompleteProcessingPipelineTest extends BaseIntegrationTest
{
    /**
     * Test the complete pipeline that was broken: livestream upload -> sermon creation
     * This test would have caught the null sermon_id bug
     *
     * @skip Requires full livestream processing setup including video segmentation service
     */
    public function test_livestream_upload_creates_sermon_with_valid_id(): void
    {
        $this->markTestSkipped('Requires full livestream processing setup including video segmentation service');
        // Create small test video file (10MB max for CI)
        $testFile = $this->copyTestFixture('test_video_livestream.mp4', 'test-integration/livestream.mp4');
        $uploadedFile = new UploadedFile(
            Storage::disk('local')->path($testFile),
            'livestream.mp4',
            'video/mp4',
            null,
            true
        );

        // Upload via API (this should complete synchronously)
        $response = $this->actingAs($this->testUser)
            ->postJson('/api/media/livestream', [
                'video' => $uploadedFile,
            ]);

        $response->assertStatus(201);
        $processingId = $response->json('processing_id');

        // Verify processing completed
        $processing = MediaProcessingLog::where('processing_id', $processingId)->first();
        $this->assertNotNull($processing);
        $this->assertEquals('completed', $processing->status);

        // CRITICAL: Verify sermon was actually created (this was the bug)
        $this->assertNotNull($processing->sermon_id, 'sermon_id should not be null');

        $sermon = Sermon::find($processing->sermon_id);
        $this->assertNotNull($sermon, 'Sermon record should exist');
        $this->assertEquals('livestream', $sermon->source_type);
        $this->assertNotEmpty($sermon->title);
        $this->assertNotEmpty($sermon->slug);
    }

    /**
     * Test the synchronous processing method directly
     *
     * @skip Requires full sermon processing service setup including file storage
     */
    public function test_sermon_processing_service_sync_method(): void
    {
        $this->markTestSkipped('Requires full sermon processing service setup including file storage');
        $service = app(SermonProcessingService::class);

        // Create uploaded file for testing
        $testFilePath = $this->copyTestFixture('test_audio_short.mp3', 'test-integration/test.mp3');
        $uploadedFile = new UploadedFile(
            Storage::disk('local')->path($testFilePath),
            'test.mp3',
            'audio/mp3',
            null,
            true
        );

        // This should NOT return null sermon_id
        $result = $service->processSermonAudio($uploadedFile, []);

        $this->assertTrue($result['success']);
        $this->assertNotNull($result['sermon_id'], 'sermon_id must not be null');
        $this->assertIsInt($result['sermon_id']);
    }
}
