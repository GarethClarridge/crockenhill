<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MediaProcessingService;
use App\Services\ProcessingResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DirectSermonVideoUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up fake storage disks for testing
        Storage::fake('local');
        Storage::fake('sermon_disk');

        // Create test user with sermon creation permissions
        $this->user = User::factory()->create([
            'email' => 'test@crockenhill.org',
            'email_verified_at' => now(),
        ]);

        // Mock video services to avoid FFmpeg dependency in tests
        $this->mockVideoServices();
    }

    protected function mockVideoServices(): void
    {
        // Mock MediaProcessingService for successful video processing
        $mockMediaProcessing = $this->createMock(MediaProcessingService::class);

        $successResult = ProcessingResult::success(
            processingId: 'test-processing-id-123',
            message: 'Sermon video processing initiated successfully',
            statusUrl: '/api/sermons/processing/test-processing-id-123/status'
        );

        $mockMediaProcessing->method('processVideo')
            ->willReturn($successResult);

        // Also mock other methods that might be called
        $mockMediaProcessing->method('processAudio')
            ->willReturn($successResult);

        $mockMediaProcessing->method('processLivestream')
            ->willReturn($successResult);

        // Bind mock to the container
        $this->app->instance(MediaProcessingService::class, $mockMediaProcessing);
    }

    /**
     * Test successful video upload and processing initiation
     */
    public function test_successful_video_upload(): void
    {
        // Create a fake video file
        $videoFile = UploadedFile::fake()->create('test-sermon.mp4', 100 * 1024, 'video/mp4'); // 100KB

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/sermons/video', [
                'file' => $videoFile,
            ]);

        $response->assertStatus(202)
            ->assertJsonStructure([
                'success',
                'processing_id',
                'message',
                'status_url',
            ])
            ->assertJson([
                'success' => true,
            ]);
    }

    /**
     * Test video upload with invalid file format
     */
    public function test_video_upload_with_invalid_format(): void
    {
        // Create a fake text file instead of video
        $invalidFile = UploadedFile::fake()->create('document.txt', 1024, 'text/plain');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/sermons/video', [
                'file' => $invalidFile,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    /**
     * Test video upload with file too large
     */
    public function test_video_upload_with_oversized_file(): void
    {
        // Create a fake video file larger than the limit (3GB, which exceeds 2GB default)
        $largeFile = UploadedFile::fake()->create('large-sermon.mp4', 3 * 1024 * 1024, 'video/mp4');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/sermons/video', [
                'file' => $largeFile,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    /**
     * Test video upload without authentication
     */
    public function test_video_upload_requires_authentication(): void
    {
        $videoFile = UploadedFile::fake()->create('test-sermon.mp4', 100 * 1024, 'video/mp4');

        $response = $this->postJson('/api/sermons/video', [
            'file' => $videoFile,
        ]);

        $response->assertStatus(401);
    }

    /**
     * Test video upload without file
     */
    public function test_video_upload_requires_file(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/sermons/video', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    /**
     * Test video upload with all supported formats
     */
    public function test_video_upload_supports_various_formats(): void
    {
        $formats = [
            ['mp4', 'video/mp4'],
            ['mov', 'video/quicktime'],
            ['avi', 'video/x-msvideo'],
            ['mkv', 'video/x-matroska'],
        ];

        foreach ($formats as $index => [$extension, $mimeType]) {
            // Create a unique user for each format to avoid rate limiting
            $user = User::factory()->create([
                'email' => "test{$index}@crockenhill.org",
                'email_verified_at' => now(),
            ]);

            $videoFile = UploadedFile::fake()->create("test-sermon.{$extension}", 100 * 1024, $mimeType);

            $response = $this->actingAs($user, 'sanctum')
                ->postJson('/api/sermons/video', [
                    'file' => $videoFile,
                ]);

            $response->assertStatus(202)
                ->assertJson([
                    'success' => true,
                ]);
        }
    }

    /**
     * Test rate limiting on video uploads
     */
    public function test_video_upload_rate_limiting(): void
    {
        $videoFile = UploadedFile::fake()->create('test-sermon.mp4', 100 * 1024, 'video/mp4');

        // First upload should succeed
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/sermons/video', [
                'file' => $videoFile,
            ]);

        $response->assertStatus(202);

        // Second upload should be rate limited (1 per minute limit)
        $videoFile2 = UploadedFile::fake()->create('test-sermon-2.mp4', 100 * 1024, 'video/mp4');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/sermons/video', [
                'file' => $videoFile2,
            ]);

        // Rate limiting should block the second request
        $response->assertStatus(429); // Too Many Requests
    }

    /**
     * Test video upload returns expected response structure
     */
    public function test_video_upload_returns_correct_structure(): void
    {
        $videoFile = UploadedFile::fake()->create('test-sermon.mp4', 100 * 1024, 'video/mp4');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/sermons/video', [
                'file' => $videoFile,
            ]);

        $response->assertStatus(202)
            ->assertJsonStructure([
                'success',
                'processing_id',
                'message',
                'status_url',
            ])
            ->assertJson([
                'success' => true,
                'processing_id' => 'test-processing-id-123',
            ]);
    }
}
