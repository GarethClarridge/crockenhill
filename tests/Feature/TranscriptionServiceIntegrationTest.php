<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\TranscriptionServiceInterface;
use App\Services\AudioTranscriptionService;
use App\Services\LocalWhisperTranscriptionService;
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
        Config::set('media-processing.transcription.service', 'mock');

        // Force the container to forget the previously resolved service
        app()->forgetInstance(TranscriptionServiceInterface::class);

        $service = app(TranscriptionServiceInterface::class);

        $this->assertInstanceOf(MockTranscriptionService::class, $service);
    }

    public function test_service_provider_binds_openai_service_when_configured()
    {
        // Configure to use OpenAI service
        Config::set('media-processing.transcription.service', 'openai');

        // Force the container to forget the previously resolved service
        app()->forgetInstance(TranscriptionServiceInterface::class);

        $service = app(TranscriptionServiceInterface::class);

        $this->assertInstanceOf(AudioTranscriptionService::class, $service);
    }

    public function test_service_provider_binds_local_whisper_service_when_configured()
    {
        Config::set('media-processing.transcription.service', 'local');

        app()->forgetInstance(TranscriptionServiceInterface::class);

        $service = app(TranscriptionServiceInterface::class);

        $this->assertInstanceOf(LocalWhisperTranscriptionService::class, $service);
    }

    public function test_mock_service_works_in_job_context()
    {
        // Configure to use mock service and ensure transcript disk matches what Storage facade uses by default
        Config::set('media-processing.transcription.service', 'mock');
        Config::set('media-processing.storage.transcript_disk', config('filesystems.default', 'local'));

        // Force the container to forget the previously resolved service
        app()->forgetInstance(TranscriptionServiceInterface::class);

        $service = app(TranscriptionServiceInterface::class);

        // Test transcription - it should return static mock transcript content
        $result = $service->transcribe('fake/path/to/audio.mp3', 'test-processing-id');

        $this->assertIsString($result);
        $this->assertStringContainsString('Romans chapter 8, verse 28', $result);
        $this->assertStringContainsString('God works for the good of those who love him', $result);

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
        Config::set('media-processing.transcription.service', 'mock');

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
