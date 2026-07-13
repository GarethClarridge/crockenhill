<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Data\ProcessingResult;
use App\Data\StandardProcessingResponse;
use App\Enums\ApiTokenAbility;
use App\Models\User;
use App\Services\Processing\UnifiedMediaProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\BuildsTestScenarios;

class MediaUploadTest extends TestCase
{
    use BuildsTestScenarios;
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeMediaDisks(['local']);

        $this->admin = $this->createVerifiedAdmin();
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test', [ApiTokenAbility::MediaProcess->value])
            ->plainTextToken;
    }

    // -------------------------------------------------------------------------
    // Authentication & authorisation
    // -------------------------------------------------------------------------

    #[Test]
    public function it_rejects_unauthenticated_upload_requests(): void
    {
        $file = $this->fakeAudioUpload();

        $this->postJson('/api/media/audio', ['file' => $file])
            ->assertUnauthorized();
    }

    #[Test]
    public function it_rejects_non_admin_users(): void
    {
        $user = User::factory()->create(['is_admin' => false, 'email_verified_at' => now()]);
        $token = $user->createToken('test', [ApiTokenAbility::MediaProcess->value])->plainTextToken;

        $file = $this->fakeAudioUpload();

        $this->withToken($token)
            ->postJson('/api/media/audio', ['file' => $file])
            ->assertForbidden();
    }

    #[Test]
    public function it_rejects_token_without_media_process_ability(): void
    {
        $token = $this->admin->createToken('test', ['some:other'])->plainTextToken;
        $file = $this->fakeAudioUpload();

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
            ->willReturn(StandardProcessingResponse::notFound($processingId));
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
            ->willReturn(StandardProcessingResponse::found(
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
            ->assertStatus(400);
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

    #[Test]
    public function it_returns_400_when_cancel_fails(): void
    {
        $processingId = 'aaaaaaaa-0000-0000-0000-000000000004';

        $mock = $this->createMock(UnifiedMediaProcessor::class);
        $mock->method('cancel')->willReturn(['success' => false, 'message' => 'Processing ID not found']);
        $this->app->instance(UnifiedMediaProcessor::class, $mock);

        $token = $this->tokenFor($this->admin);

        $this->withToken($token)
            ->deleteJson("/api/media/processing/{$processingId}")
            ->assertBadRequest()
            ->assertJsonFragment(['success' => false]);
    }

    #[Test]
    public function it_returns_400_for_malformed_processing_id_on_cancel(): void
    {
        $token = $this->tokenFor($this->admin);

        $this->withToken($token)
            ->deleteJson('/api/media/processing/!!bad!!')
            ->assertStatus(400);
    }

    // -------------------------------------------------------------------------
    // Retry endpoint
    // -------------------------------------------------------------------------

    #[Test]
    public function it_can_retry_failed_processing(): void
    {
        $processingId = 'aaaaaaaa-0000-0000-0000-000000000005';
        $mockResult = ProcessingResult::success($processingId, 'Retry initiated');

        $mock = $this->createMock(UnifiedMediaProcessor::class);
        $mock->method('retry')->willReturn($mockResult);
        $this->app->instance(UnifiedMediaProcessor::class, $mock);

        $token = $this->tokenFor($this->admin);

        $this->withToken($token)
            ->postJson("/api/media/processing/{$processingId}/retry")
            ->assertStatus(202)
            ->assertJsonFragment(['success' => true]);
    }

    #[Test]
    public function it_returns_422_when_retry_fails(): void
    {
        $processingId = 'aaaaaaaa-0000-0000-0000-000000000006';
        $mockResult = ProcessingResult::failure($processingId, 'Processing ID not found', 'NOT_FOUND');

        $mock = $this->createMock(UnifiedMediaProcessor::class);
        $mock->method('retry')->willReturn($mockResult);
        $this->app->instance(UnifiedMediaProcessor::class, $mock);

        $token = $this->tokenFor($this->admin);

        $this->withToken($token)
            ->postJson("/api/media/processing/{$processingId}/retry")
            ->assertUnprocessable()
            ->assertJsonFragment(['success' => false]);
    }

    #[Test]
    public function it_returns_400_for_malformed_processing_id_on_retry(): void
    {
        $token = $this->tokenFor($this->admin);

        $this->withToken($token)
            ->postJson('/api/media/processing/!!bad!!/retry')
            ->assertStatus(400);
    }

    #[Test]
    public function it_rejects_unauthenticated_retry_requests(): void
    {
        $processingId = 'aaaaaaaa-0000-0000-0000-000000000007';

        $this->postJson("/api/media/processing/{$processingId}/retry")
            ->assertUnauthorized();
    }

    #[Test]
    public function it_rejects_unauthenticated_cancel_requests(): void
    {
        $processingId = 'aaaaaaaa-0000-0000-0000-000000000008';

        $this->deleteJson("/api/media/processing/{$processingId}")
            ->assertUnauthorized();
    }

    // -------------------------------------------------------------------------
    // Upload exception path
    // -------------------------------------------------------------------------

    #[Test]
    public function it_returns_500_when_processor_throws_an_exception(): void
    {
        $mock = $this->createMock(UnifiedMediaProcessor::class);
        $mock->method('process')->willThrowException(new \RuntimeException('Unexpected failure'));
        $this->app->instance(UnifiedMediaProcessor::class, $mock);

        $file = UploadedFile::fake()->create('sermon.mp3', 100, 'audio/mpeg');
        $token = $this->tokenFor($this->admin);

        $this->withToken($token)
            ->postJson('/api/media/audio', ['file' => $file])
            ->assertStatus(500)
            ->assertJsonFragment(['success' => false]);
    }

    // -------------------------------------------------------------------------
    // Status with logs
    // -------------------------------------------------------------------------

    #[Test]
    public function it_returns_status_without_logs_by_default(): void
    {
        $processingId = 'aaaaaaaa-0000-0000-0000-000000000009';

        $mock = $this->createMock(UnifiedMediaProcessor::class);
        $mock->expects($this->once())
            ->method('getStatus')
            ->with($processingId)
            ->willReturn(StandardProcessingResponse::found(
                processingId: $processingId,
                status: 'processing',
                currentStep: 'transcription',
                progressPercentage: 70,
            ));
        $this->app->instance(UnifiedMediaProcessor::class, $mock);

        $token = $this->tokenFor($this->admin);

        $response = $this->withToken($token)
            ->getJson("/api/media/processing/{$processingId}/status");

        $response->assertOk()
            ->assertJsonMissingPath('processing_steps');
    }

    #[Test]
    public function it_delegates_to_get_status_with_logs_when_include_logs_requested(): void
    {
        $processingId = 'aaaaaaaa-0000-0000-0000-000000000010';

        $mock = $this->createMock(UnifiedMediaProcessor::class);
        $mock->expects($this->once())
            ->method('getStatusWithLogs')
            ->with($processingId, true, 20)
            ->willReturn(StandardProcessingResponse::found(
                processingId: $processingId,
                status: 'processing',
                currentStep: 'transcription',
                progressPercentage: 70,
            ));
        $this->app->instance(UnifiedMediaProcessor::class, $mock);

        $token = $this->tokenFor($this->admin);

        $this->withToken($token)
            ->getJson("/api/media/processing/{$processingId}/status?include_logs=true")
            ->assertOk();
    }
}
