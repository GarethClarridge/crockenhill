<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ApiTokenAbility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Tests\Traits\BypassesThrottleRequests;
use Tests\Traits\MediaProcessingTestHelpers;

class DirectSermonVideoUploadTest extends TestCase
{
    use BypassesThrottleRequests;
    use MediaProcessingTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutThrottleRequests();
        $this->fakeMediaDisks(['local', 'sermon_disk']);

        $this->user = $this->createVerifiedAdmin([
            'email' => 'test@crockenhill.org',
        ]);

        // Mock the unified processor to avoid FFmpeg dependency in tests
        $this->mockUnifiedMediaProcessor('test-processing-id-123');
    }

    public function test_successful_video_upload(): void
    {
        Sanctum::actingAs($this->user, [ApiTokenAbility::MediaProcess->value]);

        $videoFile = $this->fakeVideoUpload('test-sermon.mp4', 100 * 1024);

        $response = $this->postJson('/api/media/video', [
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

    public function test_video_upload_with_invalid_format(): void
    {
        Sanctum::actingAs($this->user, [ApiTokenAbility::MediaProcess->value]);

        $invalidFile = UploadedFile::fake()->create('document.txt', 1024, 'text/plain');

        $response = $this->postJson('/api/media/video', [
            'file' => $invalidFile,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_video_upload_with_oversized_file(): void
    {
        Sanctum::actingAs($this->user, [ApiTokenAbility::MediaProcess->value]);

        // Create a fake video file larger than the limit (3GB, which exceeds 1GB limit)
        $largeFile = UploadedFile::fake()->create('large-sermon.mp4', 3 * 1024 * 1024, 'video/mp4');

        $response = $this->postJson('/api/media/video', [
            'file' => $largeFile,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_video_upload_requires_authentication(): void
    {
        $videoFile = $this->fakeVideoUpload('test-sermon.mp4', 100 * 1024);

        $response = $this->postJson('/api/media/video', [
            'file' => $videoFile,
        ]);

        $response->assertStatus(401);
    }

    public function test_video_upload_requires_file(): void
    {
        Sanctum::actingAs($this->user, [ApiTokenAbility::MediaProcess->value]);

        $response = $this->postJson('/api/media/video', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_video_upload_supports_various_formats(): void
    {
        Sanctum::actingAs($this->user, [ApiTokenAbility::MediaProcess->value]);

        $formats = [
            ['mp4', 'video/mp4'],
            ['mov', 'video/quicktime'],
            ['avi', 'video/x-msvideo'],
            ['mkv', 'video/x-matroska'],
        ];

        foreach ($formats as [$extension, $mimeType]) {
            $videoFile = UploadedFile::fake()->create("test-sermon.{$extension}", 100 * 1024, $mimeType);

            $response = $this->postJson('/api/media/video', [
                'file' => $videoFile,
            ]);

            $response->assertStatus(202)
                ->assertJson([
                    'success' => true,
                ]);
        }
    }

    #[Group('dedicated')]
    #[Group('rate-limit')]
    public function test_video_upload_rate_limiting(): void
    {
        // Re-enable rate limiting for this specific test
        $this->withMiddleware(ThrottleRequests::class);

        $testUser = User::factory()->create([
            'email' => 'rate-limit-test@crockenhill.org',
            'email_verified_at' => now(),
            'is_admin' => true,
        ]);
        Sanctum::actingAs($testUser, [ApiTokenAbility::MediaProcess->value]);

        $videoFile = UploadedFile::fake()->create('test-sermon.mp4', 100 * 1024, 'video/mp4');

        // First upload
        $response = $this->postJson('/api/media/video', [
            'file' => $videoFile,
        ]);

        if ($response->status() === 429) {
            $response->assertStatus(429);

            return;
        }

        $response->assertStatus(202);

        // Second upload should be rate limited (1 per minute limit)
        $videoFile2 = UploadedFile::fake()->create('test-sermon-2.mp4', 100 * 1024, 'video/mp4');

        $response = $this->postJson('/api/media/video', [
            'file' => $videoFile2,
        ]);

        $response->assertStatus(429);
    }

    public function test_video_upload_returns_correct_structure(): void
    {
        Sanctum::actingAs($this->user, [ApiTokenAbility::MediaProcess->value]);

        $videoFile = UploadedFile::fake()->create('test-sermon.mp4', 100 * 1024, 'video/mp4');

        $response = $this->postJson('/api/media/video', [
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

    public function test_video_upload_accepts_auto_trim_requests(): void
    {
        Sanctum::actingAs($this->user, [ApiTokenAbility::MediaProcess->value]);

        $videoFile = $this->fakeVideoUpload('test-sermon.mp4', 100 * 1024);

        $response = $this->postJson('/api/media/video', [
            'file' => $videoFile,
            'auto_trim' => true,
            'video_processing_mode' => 'auto_trim',
        ]);

        $response->assertStatus(202)
            ->assertJson([
                'success' => true,
                'processing_id' => 'test-processing-id-123',
            ]);
    }
}
