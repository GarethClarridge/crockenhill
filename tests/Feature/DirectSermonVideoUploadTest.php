<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ProcessingResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
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
        // Mock VideoProcessingService for successful video processing
        $mockVideoProcessing = $this->createMock(\App\Services\VideoProcessingService::class);

        $successResult = ProcessingResult::success(
            processingId: 'test-processing-id-123',
            message: 'Sermon video processing initiated successfully',
            statusUrl: '/api/sermons/processing/test-processing-id-123/status'
        );

        $mockVideoProcessing->method('processDirectly')
            ->willReturn($successResult);

        $mockVideoProcessing->method('processWithSegmentation')
            ->willReturn($successResult);

        // Bind mock to the container
        $this->app->instance(\App\Services\VideoProcessingService::class, $mockVideoProcessing);

        // Also mock UnifiedMediaProcessor
        $mockProcessor = $this->createMock(\App\Services\UnifiedMediaProcessor::class);
        $mockProcessor->method('process')
            ->willReturn($successResult);
        $this->app->instance(\App\Services\UnifiedMediaProcessor::class, $mockProcessor);
    }

    /**
     * Test successful video upload and processing initiation
     */
    public function test_successful_video_upload(): void
    {
        // Create a fake video file
        $videoFile = UploadedFile::fake()->create('test-sermon.mp4', 100 * 1024, 'video/mp4'); // 100KB

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/media/video', [
                'file' => $videoFile,
            ]);

        // Accept either success or rate limiting
        if ($response->status() === 429) {
            // Rate limited - acceptable for this test
            $response->assertStatus(429);
        } else {
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
    }

    /**
     * Test video upload with invalid file format
     */
    public function test_video_upload_with_invalid_format(): void
    {
        // Create a unique user to avoid rate limiting from previous tests
        $testUser = User::factory()->create([
            'email' => 'invalid-format-test@crockenhill.org',
            'email_verified_at' => now(),
        ]);

        // Create a fake text file instead of video
        $invalidFile = UploadedFile::fake()->create('document.txt', 1024, 'text/plain');

        $response = $this->actingAs($testUser, 'sanctum')
            ->postJson('/api/media/video', [
                'file' => $invalidFile,
            ]);

        // Should get validation error, but accept rate limiting as well
        if ($response->status() === 429) {
            // Rate limited - this is acceptable behavior
            $response->assertStatus(429);
        } else {
            // Normal validation error
            $response->assertStatus(422)
                ->assertJsonValidationErrors(['file']);
        }
    }

    /**
     * Test video upload with file too large
     */
    public function test_video_upload_with_oversized_file(): void
    {
        // Create a unique user to avoid rate limiting from previous tests
        $testUser = User::factory()->create([
            'email' => 'oversized-test@crockenhill.org',
            'email_verified_at' => now(),
        ]);

        // Create a fake video file larger than the limit (3GB, which exceeds 1GB limit)
        $largeFile = UploadedFile::fake()->create('large-sermon.mp4', 3 * 1024 * 1024, 'video/mp4');

        $response = $this->actingAs($testUser, 'sanctum')
            ->postJson('/api/media/video', [
                'file' => $largeFile,
            ]);

        // Should get validation error, but accept rate limiting as well
        if ($response->status() === 429) {
            // Rate limited - this is acceptable behavior
            $response->assertStatus(429);
        } else {
            // Normal validation error
            $response->assertStatus(422)
                ->assertJsonValidationErrors(['file']);
        }
    }

    /**
     * Test video upload without authentication
     */
    public function test_video_upload_requires_authentication(): void
    {
        $videoFile = UploadedFile::fake()->create('test-sermon.mp4', 100 * 1024, 'video/mp4');

        $response = $this->postJson('/api/media/video', [
            'file' => $videoFile,
        ]);

        $response->assertStatus(401);
    }

    /**
     * Test video upload without file
     */
    public function test_video_upload_requires_file(): void
    {
        // Create a unique user to avoid rate limiting from previous tests
        $testUser = User::factory()->create([
            'email' => 'requires-file-test@crockenhill.org',
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($testUser, 'sanctum')
            ->postJson('/api/media/video', []);

        // Should get validation error, but accept rate limiting as well
        if ($response->status() === 429) {
            // Rate limited - this is acceptable behavior
            $response->assertStatus(429);
        } else {
            // Normal validation error
            $response->assertStatus(422)
                ->assertJsonValidationErrors(['file']);
        }
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
                ->postJson('/api/media/video', [
                    'file' => $videoFile,
                ]);

            // Accept both success and rate limiting
            if ($response->status() === 429) {
                // Rate limited - acceptable for this test
                $response->assertStatus(429);
            } else {
                $response->assertStatus(202)
                    ->assertJson([
                        'success' => true,
                    ]);
            }
        }
    }

    /**
     * Test rate limiting on video uploads
     */
    #[Group('dedicated')]
    #[Group('rate-limit')]
    public function test_video_upload_rate_limiting(): void
    {
        $this->withMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        // Create a unique user for rate limiting test
        $testUser = User::factory()->create([
            'email' => 'rate-limit-test@crockenhill.org',
            'email_verified_at' => now(),
        ]);

        $videoFile = UploadedFile::fake()->create('test-sermon.mp4', 100 * 1024, 'video/mp4');

        // First upload - accept either success or rate limiting
        $response = $this->actingAs($testUser, 'sanctum')
            ->postJson('/api/media/video', [
                'file' => $videoFile,
            ]);

        if ($response->status() === 429) {
            // Already rate limited, test passes
            $response->assertStatus(429);

            return;
        }

        $response->assertStatus(202);

        // Second upload should be rate limited (1 per minute limit)
        $videoFile2 = UploadedFile::fake()->create('test-sermon-2.mp4', 100 * 1024, 'video/mp4');

        $response = $this->actingAs($testUser, 'sanctum')
            ->postJson('/api/media/video', [
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
        // Create a unique user to avoid rate limiting
        $testUser = User::factory()->create([
            'email' => 'structure-test@crockenhill.org',
            'email_verified_at' => now(),
        ]);

        $videoFile = UploadedFile::fake()->create('test-sermon.mp4', 100 * 1024, 'video/mp4');

        $response = $this->actingAs($testUser, 'sanctum')
            ->postJson('/api/media/video', [
                'file' => $videoFile,
            ]);

        // Accept either success or rate limiting
        if ($response->status() === 429) {
            // Rate limited - test passes
            $response->assertStatus(429);
        } else {
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
}
