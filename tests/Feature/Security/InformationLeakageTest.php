<?php

namespace Tests\Feature\Security;

use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Exceptions\InvalidFileException;
use App\Models\MediaProcessingLog;
use App\Models\User;
use App\Services\ProcessingRunFailureHandler;
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

            $mock->shouldReceive('maxFileSizeForDisplay')
                ->andReturn('100MB');

            $mock->shouldReceive('allowedExtensionsForDisplay')
                ->andReturn('MP3, WAV, M4A');

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
        // Assert that a generic message IS in the response (using regex for resilience)
        $message = $response->json('message');
        $this->assertMatchesRegularExpression('/internal error.*initiating audio/i', $message);
    }

    #[Test]
    public function it_allows_safe_exception_messages_to_be_shown_to_the_user(): void
    {
        $user = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
        $this->actingAs($user);

        $this->mock(\App\Services\MediaValidationService::class, function ($mock) {
            $mock->shouldReceive('rulesForType')
                ->andReturn(['file' => 'required|file']);

            $mock->shouldReceive('maxFileSizeForDisplay')
                ->andReturn('100MB');

            $mock->shouldReceive('allowedExtensionsForDisplay')
                ->andReturn('MP3, WAV, M4A');

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
        $message = $response->json('message');
        $this->assertStringContainsString('Invalid file', $message);
        $this->assertStringContainsString('too large', $message);
    }

    #[Test]
    public function it_sanitizes_error_messages_in_media_processing_logs(): void
    {
        $user = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
        $this->actingAs($user);

        $processingLog = MediaProcessingLog::create([
            'processing_id' => (string) \Illuminate\Support\Str::uuid(),
            'processing_type' => MediaType::Audio,
            'original_filename' => 'test.mp3',
            'status' => ProcessingStatus::Pending,
            'current_step' => 'audio_processing_initiated',
            'owner_user_id' => $user->id,
        ]);

        app(ProcessingRunFailureHandler::class)->handle(
            $processingLog->processing_id,
            new \RuntimeException('Sensitive DB Error'),
            ProcessingRunFailureHandler::PROFILE_AUDIO
        );

        $processingLog->refresh();

        $this->assertEquals(ProcessingStatus::Failed, $processingLog->status);
        $this->assertStringContainsString('Audio processing failed:', $processingLog->error_message);
        $this->assertMatchesRegularExpression('/internal error.*audio processing/i', $processingLog->error_message);
        $this->assertStringNotContainsString('Sensitive DB Error', $processingLog->error_message);
    }

    #[Test]
    public function it_respects_safe_messages_in_global_exception_handler(): void
    {
        $user = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
        $this->actingAs($user);

        // We'll use a route that we know exists but might throw an exception we can control,
        // or just a test route if it was available.
        // Given we don't want to add routes, we'll use an existing one and mock a service it uses.

        $this->mock(\App\Repositories\SermonRepository::class, function ($mock) {
            $mock->shouldReceive('getSeriesForDisplay')
                ->andThrow(new \App\Exceptions\ProcessingException('Controlled Safe Error'));
        });

        // UnifiedMediaProcessor::getStatus can throw internal exceptions
        // but here we want to test a route-level throw.
        // Let's use an API route.

        // MediaController::status uses UnifiedMediaProcessor::getStatus
        $this->mock(UnifiedMediaProcessor::class, function ($mock) {
            $mock->shouldReceive('getStatus')
                ->andThrow(new \App\Exceptions\ProcessingException('Controlled Safe API Error'));
        });

        $response = $this->getJson('/api/media/processing/00000000-0000-4000-8000-000000000000/status');

        // If the global handler is working, it should return with the safe message
        // Actually, the catch block in MediaController catches it and returns 500
        $response->assertStatus(500);
        $response->assertJsonPath('message', 'Controlled Safe API Error');
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

        $processingLog = MediaProcessingLog::create([
            'processing_id' => (string) \Illuminate\Support\Str::uuid(),
            'processing_type' => MediaType::Audio,
            'original_filename' => 'test.mp3',
            'status' => ProcessingStatus::Pending,
            'current_step' => 'audio_processing_initiated',
            'owner_user_id' => $user->id,
        ]);

        app(ProcessingRunFailureHandler::class)->handle(
            $processingLog->processing_id,
            new \RuntimeException('Sensitive DB Error'),
            ProcessingRunFailureHandler::PROFILE_AUDIO
        );

        // Verify that the specific sensitive error message was captured in the logs
        $errorLogs = array_filter($logs, function ($log) {
            return $log->level === 'error'
                && str_contains($log->message, 'Processing run failure')
                && isset($log->context['error'])
                && $log->context['error'] === 'Sensitive DB Error';
        });

        $this->assertNotEmpty($errorLogs, 'Sensitive error message was not found in developer logs');

        // Also verify stack trace presence
        $firstLog = reset($errorLogs);
        $this->assertArrayHasKey('trace', $firstLog->context);
        $this->assertNotEmpty($firstLog->context['trace']);
    }
}
