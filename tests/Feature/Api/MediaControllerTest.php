<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Data\ProcessingResult;
use App\Enums\ApiTokenAbility;
use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\User;
use App\Services\Media\Video\VideoStorageService;
use App\Services\Processing\ProcessingRunOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MediaControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->crockenhillAdmin()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        Storage::fake('public');
        Storage::fake('local');
        Bus::fake();

        // Enable auto-trim for testing
        Config::set('media-processing.video_auto_trim.enabled', true);
        Config::set('media-processing.analysis.service', 'mock');
        Config::set('media-processing.transcription.service', 'mock');
    }

    // --- upload() ---

    #[Test]
    public function admin_can_upload_audio_file(): void
    {
        $file = UploadedFile::fake()->create('sermon.mp3', 1024, 'audio/mpeg');

        $response = $this->actingAs($this->admin)
            ->postJson('/api/media/audio', [
                'file' => $file,
            ]);

        $response->assertStatus(202);
        $response->assertJsonStructure(['success', 'message', 'processing_id', 'status_url']);

        $processingId = $response->json('processing_id');
        $this->assertDatabaseHas('media_processing_logs', [
            'processing_id' => $processingId,
            'processing_type' => MediaType::Audio->value,
            'original_filename' => 'sermon.mp3',
        ]);
    }

    #[Test]
    public function admin_can_upload_video_file_with_auto_trim(): void
    {
        $file = UploadedFile::fake()->create('sermon.mp4', 2048, 'video/mp4');

        $response = $this->actingAs($this->admin)
            ->postJson('/api/media/video', [
                'file' => $file,
                'auto_trim' => true,
            ]);

        $response->assertStatus(202);

        $processingId = $response->json('processing_id');
        $log = MediaProcessingLog::where('processing_id', $processingId)->firstOrFail();

        $this->assertEquals(MediaType::Video, $log->processing_type);
        $this->assertTrue($log->processing_metadata['trim_requested'] ?? false);
        $this->assertEquals(MediaProcessingLog::VIDEO_PROCESSING_MODE_AUTO_TRIM, $log->processing_metadata['video_processing_mode'] ?? null);
    }

    #[Test]
    public function upload_returns_404_for_invalid_type_due_to_route_constraints(): void
    {
        $file = UploadedFile::fake()->create('document.pdf', 512, 'application/pdf');

        // The route has a where constraint on type: audio|video|livestream
        $response = $this->actingAs($this->admin)
            ->postJson('/api/media/unsupported', [
                'file' => $file,
            ]);

        $response->assertStatus(404);
    }

    #[Test]
    public function upload_validates_file_constraints(): void
    {
        // 100MB is the limit for audio in config
        $file = UploadedFile::fake()->create('huge.mp3', 1024 * 101, 'audio/mpeg');

        $response = $this->actingAs($this->admin)
            ->postJson('/api/media/audio', [
                'file' => $file,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['file']);
    }

    #[Test]
    public function upload_requires_authentication(): void
    {
        $file = UploadedFile::fake()->create('sermon.mp3', 1024, 'audio/mpeg');

        $response = $this->postJson('/api/media/audio', [
            'file' => $file,
        ]);

        $response->assertStatus(401);
    }

    #[Test]
    public function upload_requires_media_process_token_ability_when_using_token(): void
    {
        $user = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);
        // Use SERVICE_UPLOAD which is NOT MEDIA_PROCESS
        $token = $user->createToken('test-token', [ApiTokenAbility::ServiceUpload->value])->plainTextToken;

        $file = UploadedFile::fake()->create('sermon.mp3', 1024, 'audio/mpeg');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/media/audio', [
                'file' => $file,
            ]);

        $response->assertStatus(403);
        $response->assertJsonPath('message', 'Missing required token ability: media:process');
    }

    // --- status() ---

    #[Test]
    public function admin_can_check_processing_status(): void
    {
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'current_step' => 'transcribing',
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/media/processing/{$log->processing_id}/status");

        $response->assertStatus(200);
        $response->assertJson([
            'found' => true,
            'processing_id' => $log->processing_id,
            'status' => 'processing',
            'current_step' => 'transcribing',
        ]);
    }

    #[Test]
    public function status_returns_404_for_missing_id(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/media/processing/'.Str::uuid().'/status');

        $response->assertStatus(404);
        $response->assertJsonPath('found', false);
    }

    #[Test]
    public function status_validates_uuid_format(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/media/processing/invalid-uuid/status');

        $response->assertStatus(400);
    }

    #[Test]
    public function status_denies_non_admins(): void
    {
        $user = User::factory()->create([
            'is_admin' => false,
            'email_verified_at' => now(),
        ]);

        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'owner_user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/media/processing/{$log->processing_id}/status");

        // The media.process middleware requires is_admin
        $response->assertStatus(403);
    }

    // --- cancel() ---

    #[Test]
    public function admin_can_cancel_pending_processing(): void
    {
        $log = MediaProcessingLog::factory()->audio()->pending()->create();

        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/media/processing/{$log->processing_id}");

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $this->assertEquals(ProcessingStatus::Cancelled, $log->fresh()->status);
    }

    #[Test]
    public function cancel_returns_400_for_already_completed_processing(): void
    {
        $log = MediaProcessingLog::factory()->audio()->completed()->create();

        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/media/processing/{$log->processing_id}");

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
    }

    // --- retry() ---

    #[Test]
    public function admin_can_retry_failed_processing(): void
    {
        $log = MediaProcessingLog::factory()->audio()->failed()->create();

        // Mock ProcessingRunOrchestrator to ensure status transitions to Pending
        $orchestrator = Mockery::mock(ProcessingRunOrchestrator::class)->makePartial();
        $orchestrator->shouldReceive('retry')->andReturnUsing(function ($log) {
            $log->update(['status' => ProcessingStatus::Pending]);

            return ProcessingResult::success($log->processing_id, 'Retrying');
        });
        $this->app->instance(ProcessingRunOrchestrator::class, $orchestrator);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/media/processing/{$log->processing_id}/retry");

        $response->assertStatus(202);
        $response->assertJsonPath('success', true);

        // In this environment, it should transition to pending if retry is successful
        $this->assertEquals(ProcessingStatus::Pending, $log->fresh()->status);
    }

    // --- confirmSegment() ---

    #[Test]
    public function admin_can_confirm_sermon_segment(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->manualReviewRequired()->create([
            'source_file_path' => 'livestreams/source.mp4',
        ]);
        $segment = LivestreamSegment::factory()->forProcessingLog($log->id)->speech()->create();

        // Mock VideoStorageService to report that the file exists
        $storage = $this->mock(VideoStorageService::class);
        $storage->shouldReceive('sourceVideoExistsForPath')->andReturn(true);

        // Mock ProcessingRunOrchestrator to avoid actually running jobs
        $orchestrator = $this->mock(ProcessingRunOrchestrator::class);
        $orchestrator->shouldReceive('resumeAfterManualReview')->once();

        $response = $this->actingAs($this->admin)
            ->postJson("/api/media/processing/{$log->processing_id}/confirm-segment", [
                'segment_id' => $segment->id,
            ]);

        $response->assertStatus(202);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('message', 'Sermon segment confirmed. Processing has been resumed.');

        $log->refresh();
        $this->assertEquals(ProcessingStatus::Pending, $log->status);
        // The confirmSegment transition updates the manual_review metadata, not sermon_segment_id directly
        $this->assertEquals($segment->id, $log->manuallyConfirmedSegmentId());
    }

    #[Test]
    public function confirm_segment_validates_segment_id(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->manualReviewRequired()->create();

        $response = $this->actingAs($this->admin)
            ->postJson("/api/media/processing/{$log->processing_id}/confirm-segment", [
                'segment_id' => -1,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['segment_id']);
    }
}
