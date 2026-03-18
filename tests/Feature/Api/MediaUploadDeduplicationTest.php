<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\ApiTokenAbility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
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

        $existingLog = ProcessingLogScenario::audio()
            ->pending()
            ->state(['file_hash' => hash('sha256', self::AUDIO_CONTENT)])
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

        $existingLog = ProcessingLogScenario::audio()
            ->processing()
            ->state(['file_hash' => hash('sha256', self::AUDIO_CONTENT)])
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

        $existingLog = ProcessingLogScenario::video()
            ->pending()
            ->state(['file_hash' => hash('sha256', self::VIDEO_CONTENT)])
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
    // Helpers
    // -------------------------------------------------------------------------

    private function tokenFor(User $user): string
    {
        return $user->createToken('test', [ApiTokenAbility::MEDIA_PROCESS->value])
            ->plainTextToken;
    }
}
