<?php

namespace Tests\Feature\Security;

use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Exceptions\InvalidFileException;
use App\Models\MediaProcessingLog;
use App\Models\User;
use App\Services\UnifiedMediaProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InformationLeakageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    #[Test]
    public function it_no_longer_leaks_internal_exception_messages_to_the_api_response(): void
    {
        $user = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
        $this->actingAs($user);

        // We need to mock validateUploadedFile but allow rulesForType
        $this->mock(\App\Services\MediaValidationService::class, function ($mock) {
            $mock->shouldReceive('rulesForType')
                ->andReturn(['file' => 'required|file']);

            $mock->shouldReceive('validateUploadedFile')
                ->andThrow(new \RuntimeException('Sensitive DB Error: table "users" not found at /var/www/html/database/schema.sql'));
        });

        $file = UploadedFile::fake()->create('test.mp3', 100);

        $response = $this->postJson('/api/media/audio', [
            'file' => $file,
            'type' => 'audio',
        ]);

        $response->assertStatus(422);
        // Assert that the sensitive message is NOT in the response
        $response->assertJsonMissing(['message' => 'Sensitive DB Error']);
        // Assert that a generic message IS in the response
        $response->assertJsonPath('message', 'Failed to initiate audio processing: An internal error occurred while initiating audio processing.');
    }

    #[Test]
    public function it_allows_safe_exception_messages_to_be_shown_to_the_user(): void
    {
        $user = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
        $this->actingAs($user);

        $this->mock(\App\Services\MediaValidationService::class, function ($mock) {
            $mock->shouldReceive('rulesForType')
                ->andReturn(['file' => 'required|file']);

            $mock->shouldReceive('validateUploadedFile')
                ->andThrow(new InvalidFileException(['File is too large']));
        });

        $file = UploadedFile::fake()->create('test.mp3', 100);

        $response = $this->postJson('/api/media/audio', [
            'file' => $file,
            'type' => 'audio',
        ]);

        $response->assertStatus(422);
        // Assert that the safe message IS in the response
        $response->assertJsonPath('message', 'Failed to initiate audio processing: Invalid file: File is too large');
    }

    #[Test]
    public function it_sanitizes_error_messages_in_media_processing_logs(): void
    {
        $user = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
        $this->actingAs($user);

        // We will trigger a failure in a queued job or similar, but for unit testing the service logic:
        $service = app(\App\Services\SermonJobPipelineService::class);

        $processingLog = MediaProcessingLog::create([
            'processing_id' => (string) \Illuminate\Support\Str::uuid(),
            'processing_type' => MediaType::Audio,
            'original_filename' => 'test.mp3',
            'status' => ProcessingStatus::PENDING,
            'current_step' => 'transcribing_audio',
            'owner_user_id' => $user->id,
        ]);

        // Simulate a job failure that calls dispatchProcessingJobs which has the catch block
        // Actually, dispatchProcessingJobs catches job chain failures.

        $job = new class {
            use \Illuminate\Bus\Queueable;
            public function handle() { throw new \RuntimeException('Sensitive DB Error'); }
        };

        // We need to use Bus::fake() or similar if we want to test the catch block,
        // but for a synchronous test we can just mock the dispatcher or trigger the failure manually
        // Since I removed the test-only branching, I will adjust the test to use a real (sync) queue

        config(['queue.default' => 'sync']);

        try {
            $service->dispatchProcessingJobs(
                [$job],
                $processingLog
            );
        } catch (\Throwable $e) {
            // In sync queue, the exception bubbles up
        }

        $processingLog->refresh();

        $this->assertEquals(ProcessingStatus::FAILED, $processingLog->status);
        $this->assertStringContainsString('Processing chain failed: An internal error occurred during the processing chain.', $processingLog->error_message);
        $this->assertStringNotContainsString('Sensitive DB Error', $processingLog->error_message);
    }

    #[Test]
    public function it_captures_full_exception_details_in_system_logs(): void
    {
        $user = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
        $this->actingAs($user);

        // Capture logs manually instead of using shouldReceive which is failing due to some reason
        $logs = [];
        \Illuminate\Support\Facades\Log::listen(function ($level) use (&$logs) {
            $logs[] = $level;
        });

        $service = app(\App\Services\SermonJobPipelineService::class);

        $processingLog = MediaProcessingLog::create([
            'processing_id' => (string) \Illuminate\Support\Str::uuid(),
            'processing_type' => MediaType::Audio,
            'original_filename' => 'test.mp3',
            'status' => ProcessingStatus::PENDING,
            'current_step' => 'transcribing_audio',
            'owner_user_id' => $user->id,
        ]);

        $job = new class {
            use \Illuminate\Bus\Queueable;
            public function handle() { throw new \RuntimeException('Sensitive DB Error'); }
        };

        config(['queue.default' => 'sync']);

        try {
            $service->dispatchProcessingJobs([$job], $processingLog);
        } catch (\Throwable $e) {}

        $this->assertNotEmpty(array_filter($logs, fn($log) => $log->level === 'error' && str_contains($log->message, 'Sermon processing job chain failed')));
    }
}
