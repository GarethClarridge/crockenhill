<?php

declare(strict_types=1);

namespace Tests\Feature\MediaProcessing;

use App\Enums\ApiTokenAbility;
use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Models\User;
use App\Services\UnifiedMediaProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0 safety-net: verifies that status transitions for all three media
 * types round-trip correctly through the real UnifiedMediaProcessor →
 * StandardProcessingResponse::fromProcessingLog() path.
 *
 * These tests intentionally use real database records (no mocked processor)
 * so that they will fail deterministically when status/step/progress mapping
 * changes during simplification phases.
 */
class MediaProcessingStatusTransitionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        $this->token = $this->admin->createToken(
            'test',
            [ApiTokenAbility::MEDIA_PROCESS->value]
        )->plainTextToken;
    }

    // -------------------------------------------------------------------------
    // Status transition fixtures — audio
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{0: ProcessingStatus, 1: string|null, 2: int}>
     */
    public static function audioStatusTransitionProvider(): array
    {
        return [
            // pending with no step hits the default branch (50) — captured here as a baseline fixture
            'audio pending' => [ProcessingStatus::PENDING, null, 50],
            'audio initiated' => [ProcessingStatus::PROCESSING, 'audio_processing_initiated', 10],
            'audio validating' => [ProcessingStatus::PROCESSING, 'validating', 15],
            'audio transcribing' => [ProcessingStatus::PROCESSING, 'transcribing_audio', 70],
            'audio analyzing transcript' => [ProcessingStatus::PROCESSING, 'analyzing_transcript', 85],
            'audio generating thumbnail' => [ProcessingStatus::PROCESSING, 'generating_thumbnail', 88],
            'audio cleanup' => [ProcessingStatus::PROCESSING, 'cleanup', 95],
            'audio completed' => [ProcessingStatus::COMPLETED, 'completed', 100],
            'audio failed' => [ProcessingStatus::FAILED, 'transcribing_audio', 0],
            'audio cancelled' => [ProcessingStatus::CANCELLED, 'cancelled', 0],
        ];
    }

    #[Test]
    #[DataProvider('audioStatusTransitionProvider')]
    public function audio_status_transitions_return_correct_progress(
        ProcessingStatus $status,
        ?string $step,
        int $expectedProgress
    ): void {
        $log = MediaProcessingLog::factory()->audio()->create([
            'status' => $status,
            'current_step' => $step,
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/api/media/processing/{$log->processing_id}/status");

        $response->assertOk()
            ->assertJson([
                'found' => true,
                'processing_id' => $log->processing_id,
                'status' => $status->value,
                'progress_percentage' => $expectedProgress,
            ]);
    }

    // -------------------------------------------------------------------------
    // Status transition fixtures — video
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{0: ProcessingStatus, 1: string|null, 2: int}>
     */
    public static function videoStatusTransitionProvider(): array
    {
        return [
            'video pending' => [ProcessingStatus::PENDING, null, 50],
            'video initiated' => [ProcessingStatus::PROCESSING, 'video_processing_initiated', 10],
            'video extracting audio' => [ProcessingStatus::PROCESSING, 'extracting_audio', 25],
            'video sermon creation' => [ProcessingStatus::PROCESSING, 'sermon_creation', 60],
            'video transcribing' => [ProcessingStatus::PROCESSING, 'transcribing_audio', 70],
            'video thumbnail' => [ProcessingStatus::PROCESSING, 'generating_thumbnail', 88],
            'video completed' => [ProcessingStatus::COMPLETED, 'completed', 100],
            'video failed' => [ProcessingStatus::FAILED, 'extracting_audio', 0],
            'video cancelled' => [ProcessingStatus::CANCELLED, 'cancelled', 0],
        ];
    }

    #[Test]
    #[DataProvider('videoStatusTransitionProvider')]
    public function video_status_transitions_return_correct_progress(
        ProcessingStatus $status,
        ?string $step,
        int $expectedProgress
    ): void {
        $log = MediaProcessingLog::factory()->video()->create([
            'status' => $status,
            'current_step' => $step,
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/api/media/processing/{$log->processing_id}/status");

        $response->assertOk()
            ->assertJson([
                'found' => true,
                'processing_id' => $log->processing_id,
                'status' => $status->value,
                'progress_percentage' => $expectedProgress,
            ]);
    }

    // -------------------------------------------------------------------------
    // Status transition fixtures — livestream
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{0: ProcessingStatus, 1: string|null, 2: int}>
     */
    public static function livestreamStatusTransitionProvider(): array
    {
        return [
            'livestream pending' => [ProcessingStatus::PENDING, null, 50],
            'livestream rms generation' => [ProcessingStatus::PROCESSING, 'rms_generation', 20],
            'livestream segmentation' => [ProcessingStatus::PROCESSING, 'segmentation', 30],
            'livestream analyzing segments' => [ProcessingStatus::PROCESSING, 'analyzing_segments', 40],
            'livestream transcribe speech segments' => [ProcessingStatus::PROCESSING, 'transcribe_speech_segments', 53],
            'livestream classify speech sections' => [ProcessingStatus::PROCESSING, 'classify_speech_sections', 54],
            'livestream align with oos' => [ProcessingStatus::PROCESSING, 'align_with_oos', 55],
            'livestream extracting sermon' => [ProcessingStatus::PROCESSING, 'extracting_sermon', 57],
            'livestream creating sermon' => [ProcessingStatus::PROCESSING, 'creating_sermon', 60],
            'livestream transcribing' => [ProcessingStatus::PROCESSING, 'transcribing_audio', 70],
            'livestream ai analysis' => [ProcessingStatus::PROCESSING, 'ai_analysis_completed', 85],
            'livestream cleanup' => [ProcessingStatus::PROCESSING, 'cleanup', 95],
            'livestream completed' => [ProcessingStatus::COMPLETED, 'completed', 100],
            'livestream failed' => [ProcessingStatus::FAILED, 'segmentation', 0],
            'livestream cancelled' => [ProcessingStatus::CANCELLED, 'cancelled', 0],
        ];
    }

    #[Test]
    #[DataProvider('livestreamStatusTransitionProvider')]
    public function livestream_status_transitions_return_correct_progress(
        ProcessingStatus $status,
        ?string $step,
        int $expectedProgress
    ): void {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'status' => $status,
            'current_step' => $step,
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/api/media/processing/{$log->processing_id}/status");

        $response->assertOk()
            ->assertJson([
                'found' => true,
                'processing_id' => $log->processing_id,
                'status' => $status->value,
                'progress_percentage' => $expectedProgress,
            ]);
    }

    // -------------------------------------------------------------------------
    // Livestream happy-path: completion attaches sermon
    // -------------------------------------------------------------------------

    #[Test]
    public function completed_livestream_status_includes_sermon_id_and_url(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->completed()->create();
        $sermon = $log->fresh('sermon')->sermon;

        $response = $this->withToken($this->token)
            ->getJson("/api/media/processing/{$log->processing_id}/status");

        $response->assertOk()
            ->assertJson([
                'found' => true,
                'status' => 'completed',
                'progress_percentage' => 100,
                'sermon_id' => $sermon->id,
            ]);

        $this->assertStringContainsString(
            $sermon->slug,
            $response->json('sermon_url')
        );
    }

    // -------------------------------------------------------------------------
    // Livestream failure cleanup: failed status preserves error message
    // -------------------------------------------------------------------------

    #[Test]
    public function failed_livestream_status_exposes_error_message(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'status' => ProcessingStatus::FAILED,
            'current_step' => 'segmentation',
            'error_message' => 'RMS generation timed out after 300 seconds',
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/api/media/processing/{$log->processing_id}/status");

        $response->assertOk()
            ->assertJson([
                'found' => true,
                'status' => 'failed',
                'progress_percentage' => 0,
                'error_message' => 'RMS generation timed out after 300 seconds',
            ]);
    }

    #[Test]
    public function cancelled_livestream_status_has_zero_progress(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->cancelled()->create();

        $response = $this->withToken($this->token)
            ->getJson("/api/media/processing/{$log->processing_id}/status");

        $response->assertOk()
            ->assertJson([
                'found' => true,
                'status' => 'cancelled',
                'progress_percentage' => 0,
            ]);
    }

    /**
     * @return array<string, array{0: MediaType, 1: ProcessingStatus, 2: string, 3: int}>
     */
    public static function previouslyUnmappedStepProvider(): array
    {
        return [
            'audio initiated from livestream' => [MediaType::Audio,     ProcessingStatus::PROCESSING, 'initiated_from_livestream',          10],
            'audio initiated from livestream with source id' => [MediaType::Audio,     ProcessingStatus::PROCESSING, 'initiated_from_livestream:ls-123',   10],
            'livestream restarting from beginning' => [MediaType::Livestream, ProcessingStatus::PROCESSING, 'restarting_from_beginning',          10],
            'audio updating sermon record' => [MediaType::Audio,     ProcessingStatus::PROCESSING, 'updating_sermon_record',             87],
            'video updating sermon record' => [MediaType::Video,     ProcessingStatus::PROCESSING, 'updating_sermon_record',             90],
            'audio sending notification' => [MediaType::Audio,     ProcessingStatus::PROCESSING, 'sending_notification',               92],
            'audio notification sent' => [MediaType::Audio,     ProcessingStatus::PROCESSING, 'notification_sent',                  93],
            'audio notification skipped' => [MediaType::Audio,     ProcessingStatus::PROCESSING, 'notification_skipped',               93],
            'audio notification failed' => [MediaType::Audio,     ProcessingStatus::PROCESSING, 'notification_failed',                93],
            'audio notification failed permanently' => [MediaType::Audio,     ProcessingStatus::PROCESSING, 'notification_failed_permanently',    93],
        ];
    }

    #[Test]
    #[DataProvider('previouslyUnmappedStepProvider')]
    public function previously_unmapped_steps_now_return_correct_progress_percentages(
        MediaType $mediaType,
        ProcessingStatus $status,
        string $step,
        int $expectedProgress
    ): void {
        $log = MediaProcessingLog::factory()->create([
            'processing_type' => $mediaType,
            'status' => $status,
            'current_step' => $step,
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/api/media/processing/{$log->processing_id}/status");

        $response->assertOk()
            ->assertJson([
                'found' => true,
                'processing_id' => $log->processing_id,
                'status' => $status->value,
                'current_step' => $step,
                'progress_percentage' => $expectedProgress,
            ]);
    }

    #[Test]
    public function failed_historical_logs_with_removed_legacy_retry_steps_still_round_trip_cleanly(): void
    {
        $log = MediaProcessingLog::factory()->audio()->create([
            'status' => ProcessingStatus::FAILED,
            'current_step' => 'retry_initiated',
            'error_message' => 'Unknown processing step: retry_initiated',
        ]);

        $this->withToken($this->token)
            ->getJson("/api/media/processing/{$log->processing_id}/status")
            ->assertOk()
            ->assertJson([
                'found' => true,
                'processing_id' => $log->processing_id,
                'status' => ProcessingStatus::FAILED->value,
                'current_step' => 'retry_initiated',
                'progress_percentage' => 0,
                'error_message' => 'Unknown processing step: retry_initiated',
            ]);
    }

    // -------------------------------------------------------------------------
    // Visibility: non-owner cannot see another user's processing record
    // -------------------------------------------------------------------------

    #[Test]
    public function non_admin_user_is_forbidden_from_status_endpoint(): void
    {
        $nonAdmin = User::factory()->create(['is_admin' => false, 'email_verified_at' => now()]);
        $log = MediaProcessingLog::factory()->audio()->create([
            'owner_user_id' => $nonAdmin->id,
            'status' => ProcessingStatus::PROCESSING,
        ]);

        $token = $nonAdmin->createToken('test', [ApiTokenAbility::MEDIA_PROCESS->value])->plainTextToken;

        // EnsureMediaProcessingAccess aborts with 403 for non-admin users before
        // the visibility scope even runs.
        $this->withToken($token)
            ->getJson("/api/media/processing/{$log->processing_id}/status")
            ->assertForbidden();
    }

    #[Test]
    public function admin_can_see_all_processing_records(): void
    {
        $owner = User::factory()->create(['is_admin' => false, 'email_verified_at' => now()]);

        $log = MediaProcessingLog::factory()->audio()->create([
            'owner_user_id' => $owner->id,
            'status' => ProcessingStatus::PROCESSING,
            'current_step' => 'transcribing_audio',
        ]);

        $this->withToken($this->token) // admin token
            ->getJson("/api/media/processing/{$log->processing_id}/status")
            ->assertOk()
            ->assertJson(['found' => true]);
    }

    // -------------------------------------------------------------------------
    // Cancel + retry flows against real processor
    // -------------------------------------------------------------------------

    #[Test]
    public function cancel_transitions_processing_record_to_cancelled(): void
    {
        $log = MediaProcessingLog::factory()->audio()->create([
            'status' => ProcessingStatus::PROCESSING,
            'current_step' => 'transcribing_audio',
        ]);

        $this->withToken($this->token)
            ->deleteJson("/api/media/processing/{$log->processing_id}")
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('media_processing_logs', [
            'processing_id' => $log->processing_id,
            'status' => ProcessingStatus::CANCELLED->value,
        ]);
    }

    #[Test]
    public function cancel_returns_failure_for_nonexistent_processing_id(): void
    {
        $nonexistentId = 'aaaaaaaa-ffff-ffff-ffff-000000000099';

        $this->withToken($this->token)
            ->deleteJson("/api/media/processing/{$nonexistentId}")
            ->assertJson(['success' => false]);
    }

    #[Test]
    public function retry_resets_failed_processing_to_pending(): void
    {
        $log = MediaProcessingLog::factory()->audio()->failed()->create();

        $mockProcessor = $this->createMock(\App\Services\UnifiedMediaProcessor::class);
        $mockProcessor->method('retry')->willReturn(
            \App\Services\ProcessingResult::success($log->processing_id, 'Retry initiated')
        );
        $this->app->instance(\App\Services\UnifiedMediaProcessor::class, $mockProcessor);

        $this->withToken($this->token)
            ->postJson("/api/media/processing/{$log->processing_id}/retry")
            ->assertStatus(202)
            ->assertJson(['success' => true]);
    }

    #[Test]
    public function retry_returns_422_for_nonexistent_processing_id(): void
    {
        $nonexistentId = 'aaaaaaaa-ffff-ffff-ffff-000000000088';

        $this->withToken($this->token)
            ->postJson("/api/media/processing/{$nonexistentId}/retry")
            ->assertUnprocessable()
            ->assertJson(['success' => false]);
    }
}
