<?php

namespace Tests\Feature\Api;

use App\Enums\ApiTokenAbility;
use App\Models\User;
use App\Services\ProcessingResult;
use App\Services\UnifiedMediaProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MediaUploadTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->admin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test', [ApiTokenAbility::MEDIA_PROCESS->value])
            ->plainTextToken;
    }

    // -------------------------------------------------------------------------
    // Authentication & authorisation
    // -------------------------------------------------------------------------

    #[Test]
    public function it_rejects_unauthenticated_upload_requests(): void
    {
        $file = UploadedFile::fake()->create('sermon.mp3', 100, 'audio/mpeg');

        $this->postJson('/api/media/audio', ['file' => $file])
            ->assertUnauthorized();
    }

    #[Test]
    public function it_rejects_non_admin_users(): void
    {
        $user = User::factory()->create(['is_admin' => false, 'email_verified_at' => now()]);
        $token = $user->createToken('test', [ApiTokenAbility::MEDIA_PROCESS->value])->plainTextToken;

        $file = UploadedFile::fake()->create('sermon.mp3', 100, 'audio/mpeg');

        $this->withToken($token)
            ->postJson('/api/media/audio', ['file' => $file])
            ->assertForbidden();
    }

    #[Test]
    public function it_rejects_token_without_media_process_ability(): void
    {
        $token = $this->admin->createToken('test', ['some:other'])->plainTextToken;
        $file = UploadedFile::fake()->create('sermon.mp3', 100, 'audio/mpeg');

        $this->withToken($token)
            ->postJson('/api/media/audio', ['file' => $file])
            ->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // Type routing
    // -------------------------------------------------------------------------

    #[Test]
    public function it_rejects_unsupported_media_types(): void
    {
        // Route constraint (audio|video|livestream) returns 404 for unknown types
        $token = $this->tokenFor($this->admin);

        $this->withToken($token)
            ->postJson('/api/media/podcast', [])
            ->assertNotFound();
    }

    // -------------------------------------------------------------------------
    // Happy paths
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function uploadTypeProvider(): array
    {
        return [
            'audio' => ['audio', 'sermon.mp3', 'audio/mpeg'],
            'video' => ['video', 'sermon.mp4', 'video/mp4'],
            'livestream' => ['livestream', 'livestream.mp4', 'video/mp4'],
        ];
    }

    #[Test]
    #[DataProvider('uploadTypeProvider')]
    public function it_accepts_valid_upload_and_returns_202(string $type, string $filename, string $mime): void
    {
        $processingId = 'aaaaaaaa-0000-0000-0000-000000000001';
        $mockResult = ProcessingResult::success($processingId, 'Processing started');

        $mock = $this->createMock(UnifiedMediaProcessor::class);
        $mock->expects($this->once())->method('process')->willReturn($mockResult);
        $this->app->instance(UnifiedMediaProcessor::class, $mock);

        $file = UploadedFile::fake()->create($filename, 100, $mime);
        $token = $this->tokenFor($this->admin);

        $this->withToken($token)
            ->postJson("/api/media/{$type}", ['file' => $file])
            ->assertStatus(202)
            ->assertJsonFragment(['success' => true]);
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    #[Test]
    public function it_returns_422_when_no_file_is_provided(): void
    {
        $token = $this->tokenFor($this->admin);

        $this->withToken($token)
            ->postJson('/api/media/audio', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
    }

    // -------------------------------------------------------------------------
    // Processor failure
    // -------------------------------------------------------------------------

    #[Test]
    public function it_returns_422_when_processor_reports_failure(): void
    {
        $mockResult = ProcessingResult::failure('proc-1', 'Transcription error', 'ERR_TRANSCRIPTION');

        $mock = $this->createMock(UnifiedMediaProcessor::class);
        $mock->method('process')->willReturn($mockResult);
        $this->app->instance(UnifiedMediaProcessor::class, $mock);

        $file = UploadedFile::fake()->create('sermon.mp3', 100, 'audio/mpeg');
        $token = $this->tokenFor($this->admin);

        $this->withToken($token)
            ->postJson('/api/media/audio', ['file' => $file])
            ->assertUnprocessable()
            ->assertJsonFragment(['success' => false]);
    }

    // -------------------------------------------------------------------------
    // Status endpoint
    // -------------------------------------------------------------------------

    #[Test]
    public function it_returns_404_for_unknown_processing_id(): void
    {
        $processingId = 'aaaaaaaa-0000-0000-0000-000000000099';

        $mock = $this->createMock(UnifiedMediaProcessor::class);
        $mock->method('getStatus')
            ->willReturn(\App\Data\StandardProcessingResponse::notFound($processingId));
        $this->app->instance(UnifiedMediaProcessor::class, $mock);

        $token = $this->tokenFor($this->admin);

        $this->withToken($token)
            ->getJson("/api/media/processing/{$processingId}/status")
            ->assertNotFound();
    }

    #[Test]
    public function it_returns_200_with_status_for_known_processing_id(): void
    {
        $processingId = 'aaaaaaaa-0000-0000-0000-000000000002';

        $mock = $this->createMock(UnifiedMediaProcessor::class);
        $mock->method('getStatus')
            ->willReturn(\App\Data\StandardProcessingResponse::found(
                processingId: $processingId,
                status: 'processing',
                currentStep: 'transcription',
                progressPercentage: 40,
            ));
        $this->app->instance(UnifiedMediaProcessor::class, $mock);

        $token = $this->tokenFor($this->admin);

        $this->withToken($token)
            ->getJson("/api/media/processing/{$processingId}/status")
            ->assertOk()
            ->assertJsonFragment(['status' => 'processing']);
    }

    #[Test]
    public function it_returns_400_for_malformed_processing_id(): void
    {
        $token = $this->tokenFor($this->admin);

        $this->withToken($token)
            ->getJson('/api/media/processing/!!bad!!/status')
            ->assertBadRequest();
    }

    // -------------------------------------------------------------------------
    // Cancel endpoint
    // -------------------------------------------------------------------------

    #[Test]
    public function it_can_cancel_processing(): void
    {
        $processingId = 'aaaaaaaa-0000-0000-0000-000000000003';

        $mock = $this->createMock(UnifiedMediaProcessor::class);
        $mock->method('cancel')->willReturn(['success' => true, 'message' => 'Cancelled']);
        $this->app->instance(UnifiedMediaProcessor::class, $mock);

        $token = $this->tokenFor($this->admin);

        $this->withToken($token)
            ->deleteJson("/api/media/processing/{$processingId}")
            ->assertOk()
            ->assertJsonFragment(['success' => true]);
    }
}
