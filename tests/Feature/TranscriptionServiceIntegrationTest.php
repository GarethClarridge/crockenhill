<?php

namespace Tests\Feature;

use App\Contracts\TranscriptionServiceInterface;
use App\Services\AudioTranscriptionService;
use App\Services\MockTranscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TranscriptionServiceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_provider_binds_mock_service_when_configured()
    {
        // Configure to use mock service
        Config::set('media-processing.transcription.service_type', 'mock');

        // Force the container to forget the previously resolved service
        app()->forgetInstance(TranscriptionServiceInterface::class);

        $service = app(TranscriptionServiceInterface::class);

        $this->assertInstanceOf(MockTranscriptionService::class, $service);
    }

    public function test_service_provider_binds_openai_service_when_configured()
    {
        // Configure to use OpenAI service
        Config::set('media-processing.transcription.service_type', 'openai');

        // Force the container to forget the previously resolved service
        app()->forgetInstance(TranscriptionServiceInterface::class);

        $service = app(TranscriptionServiceInterface::class);

        $this->assertInstanceOf(AudioTranscriptionService::class, $service);
    }

    public function test_mock_service_works_in_job_context()
    {
        // Configure to use mock service
        Config::set('media-processing.transcription.service_type', 'mock');

        // Force the container to forget the previously resolved service
        app()->forgetInstance(TranscriptionServiceInterface::class);

        // Create the default transcript file
        Storage::put('transcripts/sermon_7.md', 'Mock sermon transcript for testing.');

        $service = app(TranscriptionServiceInterface::class);

        // Test transcription
        $result = $service->transcribe('fake/path/to/audio.mp3', 'test-processing-id');

        $this->assertIsString($result);
        $this->assertEquals('Mock sermon transcript for testing.', $result);

        // Test storage and retrieval
        $sermonId = 999;
        $filePath = $service->storeTranscript($sermonId, $result);

        $this->assertStringContainsString('sermon_999.md', $filePath);
        $this->assertTrue(Storage::exists($filePath));

        $retrieved = $service->getTranscript($sermonId);
        $this->assertEquals($result, $retrieved);
    }

    public function test_transcription_job_can_resolve_service_interface()
    {
        // This test ensures that dependency injection works for the TranscribeAudio job
        Config::set('media-processing.transcription.service_type', 'mock');

        // Force the container to forget the previously resolved service
        app()->forgetInstance(TranscriptionServiceInterface::class);

        // The job constructor accepts TranscriptionServiceInterface
        $service = app(TranscriptionServiceInterface::class);

        $this->assertInstanceOf(TranscriptionServiceInterface::class, $service);
        $this->assertInstanceOf(MockTranscriptionService::class, $service);
    }

    public function test_interface_methods_are_consistent_between_implementations()
    {
        $methods = [
            'transcribe',
            'storeTranscript',
            'getTranscript',
            'transcriptExists',
            'deleteTranscript',
            'cleanupOnFailure',
            'getTranscriptPath',
        ];

        // Test MockTranscriptionService has all required methods
        $mockReflection = new \ReflectionClass(MockTranscriptionService::class);
        foreach ($methods as $method) {
            $this->assertTrue($mockReflection->hasMethod($method),
                "MockTranscriptionService missing method: {$method}");
        }

        // Test AudioTranscriptionService has all required methods
        $openaiReflection = new \ReflectionClass(AudioTranscriptionService::class);
        foreach ($methods as $method) {
            $this->assertTrue($openaiReflection->hasMethod($method),
                "AudioTranscriptionService missing method: {$method}");
        }
    }
}
