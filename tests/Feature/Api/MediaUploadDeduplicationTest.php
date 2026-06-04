<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\ApiTokenAbility;
use App\Enums\MediaType;
use App\Models\MediaProcessingLog;
use App\Models\User;
use App\Services\Processing\ProcessingInitiator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\MediaUploadScenario;
use Tests\Support\ProcessingLogScenario;
use Tests\TestCase;
use Tests\Traits\BuildsTestScenarios;

class MediaUploadDeduplicationTest extends TestCase
{
    use BuildsTestScenarios;
    use RefreshDatabase;

    /** SHA-256 of the shared audio file content used across all audio dedup tests. */
    private const AUDIO_CONTENT = 'fake dedup audio content';

    private const VIDEO_CONTENT = 'fake dedup video content';

    private const LIVESTREAM_CONTENT = 'fake dedup livestream content';

    private const SHARED_CONTENT = 'fake dedup shared content for cross-pipeline tests';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeMediaDisks(['local', 'public']);

        $this->admin = $this->createVerifiedAdmin();
    }

    // -------------------------------------------------------------------------
    // Audio deduplication
    // -------------------------------------------------------------------------

    #[Test]
    public function it_returns_existing_processing_id_when_same_audio_file_is_uploaded_while_pending(): void
    {
        Bus::fake();

        $hash = hash('sha256', self::AUDIO_CONTENT);
        $existingLog = ProcessingLogScenario::audio()
            ->pending()
            ->withOwner($this->admin)
            ->state([
                'file_hash' => $hash,
                'dedup_key' => $this->dedupKey($hash, MediaType::Audio),
            ])
            ->create();

        $file = MediaUploadScenario::withContent('sermon.mp3', self::AUDIO_CONTENT, 'audio/mpeg');

        $this->withToken($this->tokenFor($this->admin))
            ->postJson('/api/media/audio', ['file' => $file])
            ->assertStatus(202)
            ->assertJsonFragment(['processing_id' => $existingLog->processing_id]);

        $this->assertDatabaseCount('media_processing_logs', 1);
    }

    #[Test]
    public function it_returns_existing_processing_id_when_same_audio_file_is_uploaded_while_processing(): void
    {
        Bus::fake();

        $hash = hash('sha256', self::AUDIO_CONTENT);
        $existingLog = ProcessingLogScenario::audio()
            ->processing()
            ->withOwner($this->admin)
            ->state([
                'file_hash' => $hash,
                'dedup_key' => $this->dedupKey($hash, MediaType::Audio),
            ])
            ->create();

        $file = MediaUploadScenario::withContent('sermon.mp3', self::AUDIO_CONTENT, 'audio/mpeg');

        $this->withToken($this->tokenFor($this->admin))
            ->postJson('/api/media/audio', ['file' => $file])
            ->assertStatus(202)
            ->assertJsonFragment(['processing_id' => $existingLog->processing_id]);

        $this->assertDatabaseCount('media_processing_logs', 1);
    }

    #[Test]
    public function it_creates_new_processing_run_when_previous_audio_processing_completed(): void
    {
        Bus::fake();

        ProcessingLogScenario::audio()
            ->completed()
            ->state(['file_hash' => hash('sha256', self::AUDIO_CONTENT)])
            ->create();

        $file = MediaUploadScenario::withContent('sermon.mp3', self::AUDIO_CONTENT, 'audio/mpeg');

        $response = $this->withToken($this->tokenFor($this->admin))
            ->postJson('/api/media/audio', ['file' => $file])
            ->assertStatus(202);

        $this->assertDatabaseCount('media_processing_logs', 2);
        $this->assertNotNull($response->json('processing_id'));
    }

    #[Test]
    public function it_creates_new_processing_run_when_previous_audio_processing_failed(): void
    {
        Bus::fake();

        ProcessingLogScenario::audio()
            ->failed()
            ->state(['file_hash' => hash('sha256', self::AUDIO_CONTENT)])
            ->create();

        $file = MediaUploadScenario::withContent('sermon.mp3', self::AUDIO_CONTENT, 'audio/mpeg');

        $response = $this->withToken($this->tokenFor($this->admin))
            ->postJson('/api/media/audio', ['file' => $file])
            ->assertStatus(202);

        $this->assertDatabaseCount('media_processing_logs', 2);
        $this->assertNotNull($response->json('processing_id'));
    }

    // -------------------------------------------------------------------------
    // Video deduplication
    // -------------------------------------------------------------------------

    #[Test]
    public function it_returns_existing_processing_id_when_same_video_file_is_uploaded_while_pending(): void
    {
        Bus::fake();

        $hash = hash('sha256', self::VIDEO_CONTENT);
        $existingLog = ProcessingLogScenario::video()
            ->pending()
            ->withOwner($this->admin)
            ->state([
                'file_hash' => $hash,
                'dedup_key' => $this->dedupKey($hash, MediaType::Video),
            ])
            ->create();

        $file = MediaUploadScenario::withContent('sermon.mp4', self::VIDEO_CONTENT, 'video/mp4');

        $this->withToken($this->tokenFor($this->admin))
            ->postJson('/api/media/video', ['file' => $file])
            ->assertStatus(202)
            ->assertJsonFragment(['processing_id' => $existingLog->processing_id]);

        $this->assertDatabaseCount('media_processing_logs', 1);
    }

    #[Test]
    public function it_creates_new_processing_run_when_previous_video_processing_completed(): void
    {
        Bus::fake();

        ProcessingLogScenario::video()
            ->completed()
            ->state(['file_hash' => hash('sha256', self::VIDEO_CONTENT)])
            ->create();

        $file = MediaUploadScenario::withContent('sermon.mp4', self::VIDEO_CONTENT, 'video/mp4');

        $response = $this->withToken($this->tokenFor($this->admin))
            ->postJson('/api/media/video', ['file' => $file])
            ->assertStatus(202);

        $this->assertDatabaseCount('media_processing_logs', 2);
        $this->assertNotNull($response->json('processing_id'));
    }

    // -------------------------------------------------------------------------
    // Livestream deduplication
    // -------------------------------------------------------------------------

    #[Test]
    public function it_returns_existing_processing_id_when_same_livestream_file_is_uploaded_while_pending(): void
    {
        Bus::fake();

        $hash = hash('sha256', self::LIVESTREAM_CONTENT);
        $existingLog = ProcessingLogScenario::livestream()
            ->pending()
            ->withOwner($this->admin)
            ->state([
                'file_hash' => $hash,
                'dedup_key' => $this->dedupKey($hash, MediaType::Livestream),
            ])
            ->create();

        $file = MediaUploadScenario::withContent('recording.mp4', self::LIVESTREAM_CONTENT, 'video/mp4');

        $this->withToken($this->tokenFor($this->admin))
            ->postJson('/api/media/livestream', ['file' => $file])
            ->assertStatus(202)
            ->assertJsonFragment(['processing_id' => $existingLog->processing_id]);

        $this->assertDatabaseCount('media_processing_logs', 1);
    }

    #[Test]
    public function it_returns_existing_processing_id_when_same_livestream_file_is_uploaded_while_processing(): void
    {
        Bus::fake();

        $hash = hash('sha256', self::LIVESTREAM_CONTENT);
        $existingLog = ProcessingLogScenario::livestream()
            ->processing()
            ->withOwner($this->admin)
            ->state([
                'file_hash' => $hash,
                'dedup_key' => $this->dedupKey($hash, MediaType::Livestream),
            ])
            ->create();

        $file = MediaUploadScenario::withContent('recording.mp4', self::LIVESTREAM_CONTENT, 'video/mp4');

        $this->withToken($this->tokenFor($this->admin))
            ->postJson('/api/media/livestream', ['file' => $file])
            ->assertStatus(202)
            ->assertJsonFragment(['processing_id' => $existingLog->processing_id]);

        $this->assertDatabaseCount('media_processing_logs', 1);
    }

    // -------------------------------------------------------------------------
    // Hash stored on new records
    // -------------------------------------------------------------------------

    #[Test]
    public function it_stores_file_hash_on_new_media_processing_log(): void
    {
        Bus::fake();

        $file = MediaUploadScenario::withContent('sermon.mp3', self::AUDIO_CONTENT, 'audio/mpeg');

        $this->withToken($this->tokenFor($this->admin))
            ->postJson('/api/media/audio', ['file' => $file])
            ->assertStatus(202);

        $this->assertDatabaseHas('media_processing_logs', [
            'file_hash' => hash('sha256', self::AUDIO_CONTENT),
        ]);
    }

    // -------------------------------------------------------------------------
    // Cross-pipeline isolation
    // -------------------------------------------------------------------------

    #[Test]
    public function it_creates_separate_run_when_same_file_uploaded_to_audio_while_video_is_pending(): void
    {
        Bus::fake();

        $hash = hash('sha256', self::SHARED_CONTENT);

        ProcessingLogScenario::video()
            ->pending()
            ->withOwner($this->admin)
            ->state([
                'file_hash' => $hash,
                'dedup_key' => $this->dedupKey($hash, MediaType::Video),
            ])
            ->create();

        $file = MediaUploadScenario::withContent('sermon.mp3', self::SHARED_CONTENT, 'audio/mpeg');

        $response = $this->withToken($this->tokenFor($this->admin))
            ->postJson('/api/media/audio', ['file' => $file])
            ->assertStatus(202);

        $this->assertDatabaseCount('media_processing_logs', 2);
        $this->assertNotNull($response->json('processing_id'));
    }

    #[Test]
    public function it_creates_separate_run_when_same_file_uploaded_to_video_while_livestream_is_pending(): void
    {
        Bus::fake();

        $hash = hash('sha256', self::SHARED_CONTENT);

        ProcessingLogScenario::livestream()
            ->pending()
            ->withOwner($this->admin)
            ->state([
                'file_hash' => $hash,
                'dedup_key' => $this->dedupKey($hash, MediaType::Livestream),
            ])
            ->create();

        $file = MediaUploadScenario::withContent('sermon.mp4', self::SHARED_CONTENT, 'video/mp4');

        $response = $this->withToken($this->tokenFor($this->admin))
            ->postJson('/api/media/video', ['file' => $file])
            ->assertStatus(202);

        $this->assertDatabaseCount('media_processing_logs', 2);
        $this->assertNotNull($response->json('processing_id'));
    }

    // -------------------------------------------------------------------------
    // Video mode isolation
    // -------------------------------------------------------------------------

    #[Test]
    public function it_creates_separate_run_when_auto_trim_uploaded_while_full_video_is_pending(): void
    {
        Bus::fake();

        $hash = hash('sha256', self::VIDEO_CONTENT);

        ProcessingLogScenario::video()
            ->pending()
            ->withOwner($this->admin)
            ->state([
                'file_hash' => $hash,
                'dedup_key' => $this->dedupKey($hash, MediaType::Video),
            ])
            ->create();

        $file = MediaUploadScenario::withContent('sermon.mp4', self::VIDEO_CONTENT, 'video/mp4');

        $response = $this->withToken($this->tokenFor($this->admin))
            ->postJson('/api/media/video', ['file' => $file, 'video_processing_mode' => 'auto_trim'])
            ->assertStatus(202);

        $this->assertDatabaseCount('media_processing_logs', 2);
        $this->assertNotNull($response->json('processing_id'));
    }

    // -------------------------------------------------------------------------
    // Cross-owner isolation
    // -------------------------------------------------------------------------

    #[Test]
    public function it_creates_separate_run_when_second_admin_uploads_identical_file_to_same_pipeline(): void
    {
        Bus::fake();

        $secondAdmin = $this->createVerifiedAdmin();
        $hash = hash('sha256', self::AUDIO_CONTENT);

        $existingLog = ProcessingLogScenario::audio()
            ->pending()
            ->withOwner($this->admin)
            ->state([
                'file_hash' => $hash,
                'dedup_key' => $this->dedupKey($hash, MediaType::Audio),
            ])
            ->create();

        $file = MediaUploadScenario::withContent('sermon.mp3', self::AUDIO_CONTENT, 'audio/mpeg');

        $response = $this->withToken($this->tokenFor($secondAdmin))
            ->postJson('/api/media/audio', ['file' => $file])
            ->assertStatus(202);

        $this->assertNotSame($existingLog->processing_id, $response->json('processing_id'));
        $this->assertDatabaseCount('media_processing_logs', 2);
        $this->assertDatabaseHas('media_processing_logs', [
            'processing_id' => $response->json('processing_id'),
            'owner_user_id' => $secondAdmin->id,
            'dedup_key' => $this->dedupKey($hash, MediaType::Audio, owner: $secondAdmin),
        ]);
    }

    // -------------------------------------------------------------------------
    // Concurrent race: unique constraint violation path
    // -------------------------------------------------------------------------

    #[Test]
    public function it_returns_existing_run_when_unique_constraint_violation_occurs_during_concurrent_upload(): void
    {
        Bus::fake();

        $hash = hash('sha256', self::AUDIO_CONTENT);
        $dedupKey = $this->dedupKey($hash, MediaType::Audio);

        // Create the race winner's log without a dedup_key so the preflight
        // check passes (simulating both requests reading null simultaneously).
        $existingLog = ProcessingLogScenario::audio()
            ->pending()
            ->withOwner($this->admin)
            ->state([
                'file_hash' => $hash,
                'dedup_key' => null,
            ])
            ->create();

        // Mock ProcessingInitiator to: (1) set the dedup_key on the existing log
        // simulating the race winner committing, then (2) throw the constraint
        // violation as our insert conflicts with that committed row.
        $this->partialMock(
            ProcessingInitiator::class,
            function (MockInterface $mock) use ($existingLog, $dedupKey) {
                $mock->shouldReceive('initiateProcessing')
                    ->once()
                    ->andReturnUsing(function () use ($existingLog, $dedupKey) {
                        $existingLog->update(['dedup_key' => $dedupKey]);
                        throw new UniqueConstraintViolationException('test', 'INSERT INTO `media_processing_logs`', [], new \RuntimeException('Duplicate entry'));
                    });
            }
        );

        $file = MediaUploadScenario::withContent('sermon.mp3', self::AUDIO_CONTENT, 'audio/mpeg');

        $this->withToken($this->tokenFor($this->admin))
            ->postJson('/api/media/audio', ['file' => $file])
            ->assertStatus(202)
            ->assertJsonFragment(['processing_id' => $existingLog->processing_id]);

        $this->assertDatabaseCount('media_processing_logs', 1);
    }

    // -------------------------------------------------------------------------
    // Terminal state frees the dedup slot
    // -------------------------------------------------------------------------

    #[Test]
    public function it_creates_new_run_after_previous_run_completes_and_clears_dedup_key(): void
    {
        Bus::fake();

        $hash = hash('sha256', self::AUDIO_CONTENT);

        // Completed run: dedup_key is NULL (cleared on completion)
        ProcessingLogScenario::audio()
            ->completed()
            ->state([
                'file_hash' => $hash,
                'dedup_key' => null,
            ])
            ->create();

        $file = MediaUploadScenario::withContent('sermon.mp3', self::AUDIO_CONTENT, 'audio/mpeg');

        $response = $this->withToken($this->tokenFor($this->admin))
            ->postJson('/api/media/audio', ['file' => $file])
            ->assertStatus(202);

        $this->assertDatabaseCount('media_processing_logs', 2);
        $this->assertNotNull($response->json('processing_id'));

        // New log should have a dedup_key set
        $newLog = MediaProcessingLog::query()
            ->where('processing_id', $response->json('processing_id'))
            ->first();

        $this->assertNotNull($newLog);
        $this->assertSame($this->dedupKey($hash, MediaType::Audio), $newLog->dedup_key);
    }

    // -------------------------------------------------------------------------
    // Manual review frees the dedup slot (P2)
    // -------------------------------------------------------------------------

    #[Test]
    public function it_creates_new_run_when_previous_run_is_awaiting_manual_review(): void
    {
        Bus::fake();

        $hash = hash('sha256', self::LIVESTREAM_CONTENT);

        // Manual-review run: dedup_key cleared when markForManualReview fires
        ProcessingLogScenario::livestream()
            ->failed()
            ->state([
                'file_hash' => $hash,
                'dedup_key' => null,
                'current_step' => 'manual_review_required',
            ])
            ->create();

        $file = MediaUploadScenario::withContent('recording.mp4', self::LIVESTREAM_CONTENT, 'video/mp4');

        $response = $this->withToken($this->tokenFor($this->admin))
            ->postJson('/api/media/livestream', ['file' => $file])
            ->assertStatus(202);

        $this->assertDatabaseCount('media_processing_logs', 2);
        $this->assertNotNull($response->json('processing_id'));
    }

    // -------------------------------------------------------------------------
    // Retried run reclaims the dedup slot (P3)
    // -------------------------------------------------------------------------

    #[Test]
    public function it_blocks_duplicate_upload_while_a_retried_run_is_pending(): void
    {
        Bus::fake();

        $hash = hash('sha256', self::AUDIO_CONTENT);
        $dedupKey = $this->dedupKey($hash, MediaType::Audio);

        // Simulates a run that was failed, then retried: resetForRetry restores dedup_key
        $retriedLog = ProcessingLogScenario::audio()
            ->pending()
            ->withOwner($this->admin)
            ->state([
                'file_hash' => $hash,
                'dedup_key' => $dedupKey,
            ])
            ->create();

        $file = MediaUploadScenario::withContent('sermon.mp3', self::AUDIO_CONTENT, 'audio/mpeg');

        $this->withToken($this->tokenFor($this->admin))
            ->postJson('/api/media/audio', ['file' => $file])
            ->assertStatus(202)
            ->assertJsonFragment(['processing_id' => $retriedLog->processing_id]);

        $this->assertDatabaseCount('media_processing_logs', 1);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function dedupKey(
        string $hash,
        MediaType $type,
        string $videoMode = MediaProcessingLog::VIDEO_PROCESSING_MODE_FULL_VIDEO,
        ?User $owner = null
    ): string {
        return MediaProcessingLog::makeDedupKey($hash, $type, $videoMode, $owner?->id ?? $this->admin->id);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test', [ApiTokenAbility::MEDIA_PROCESS->value])
            ->plainTextToken;
    }
}
