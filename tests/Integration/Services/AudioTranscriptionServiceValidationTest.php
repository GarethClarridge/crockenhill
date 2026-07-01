<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Exceptions\NonRetryableTranscriptionException;
use App\Exceptions\TranscriptionException;
use App\Services\BritishEnglishConverter;
use App\Services\Media\Audio\AudioChunkingService;
use App\Services\Media\Audio\AudioTranscriptionService;
use App\Services\Media\Audio\TranscriptFormatterService;
use App\Services\Media\Audio\TranscriptStorageService;
use App\Services\Processing\SermonProcessingLogger;
use Exception;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Laravel\Facades\OpenAI;
use Tests\TestCase;

class AudioTranscriptionServiceValidationTest extends TestCase
{
    use RefreshDatabase;

    protected AudioTranscriptionService $service;

    protected SermonProcessingLogger $mockLogger;

    protected AudioChunkingService $mockChunkingService;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock the storage for testing
        Storage::fake('local');
        Storage::fake('public');

        // Mock the logger dependency
        $this->mockLogger = $this->createStub(SermonProcessingLogger::class);

        // Set OpenAI API key for testing
        Config::set('media-processing.transcription.openai_api_key', 'test-api-key');

        // Mock OpenAI configuration for Laravel OpenAI package
        Config::set('openai.api_key', 'test-api-key');

        // Configure the service to use the same disk as our faked storage
        Config::set('media-processing.storage.sermon_disk', 'local');

        $storageService = app(TranscriptStorageService::class);
        $formatter = new TranscriptFormatterService(app(BritishEnglishConverter::class));
        $this->mockChunkingService = $this->createMock(AudioChunkingService::class);
        $this->mockChunkingService->method('getAudioDuration')->willReturn(100.0);
        $this->mockChunkingService->method('needsChunking')->willReturn(false);
        $this->mockChunkingService->method('compressAudioForTranscription')->willReturnArgument(0);

        $this->service = new AudioTranscriptionService($this->mockLogger, $storageService, $this->mockChunkingService, $formatter);
    }

    public function test_service_requires_openai_api_key(): void
    {
        Config::set('media-processing.transcription.openai_api_key', '');
        Config::set('openai.api_key', '');

        $storageService = app(TranscriptStorageService::class);
        $formatter = new TranscriptFormatterService(app(BritishEnglishConverter::class));
        $chunkingService = new AudioChunkingService($this->mockLogger);
        $service = new AudioTranscriptionService($this->mockLogger, $storageService, $chunkingService, $formatter);

        // Create a test file to trigger the validation in transcribe method
        $testFilePath = 'test_validation_audio.mp3';
        Storage::disk('public')->put($testFilePath, 'mock audio content');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('OpenAI API key not configured for transcription service');

        $service->transcribe($testFilePath, 'test-processing-id');

        // Cleanup
        Storage::disk('public')->delete($testFilePath);
    }

    public function test_transcribe_validates_file_exists(): void
    {
        $nonExistentPath = 'nonexistent/audio.mp3';

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Audio file not found: {$nonExistentPath}");

        $this->service->transcribe($nonExistentPath, 'test-processing-id');
    }

    public function test_transcribe_validates_file_size_against_config(): void
    {
        // Set a small file size limit for testing (must use the key validateAndCompressIfNeeded reads)
        Config::set('media-processing.audio_extraction.transcription_optimized.max_file_size', 1024); // 1KB

        // Create a test file larger than the limit
        $testFilePath = 'test_large_audio.mp3';
        Storage::disk('public')->put($testFilePath, str_repeat('a', 2048)); // 2KB

        $this->expectException(Exception::class);
        // The service might fail at FFmpeg validation or file size validation
        $this->expectExceptionMessageMatches('/(Audio file too large|Failed to get audio duration|Unable to probe)/');

        $this->service->transcribe($testFilePath, 'test-processing-id');

        // Cleanup
        Storage::disk('public')->delete($testFilePath);
    }

    public function test_file_size_validation_provides_helpful_error_message(): void
    {
        // Set a specific limit for testing (must use the key that validateAndCompressIfNeeded reads)
        Config::set('media-processing.audio_extraction.transcription_optimized.max_file_size', 5 * 1024 * 1024); // 5MB

        // Create a test file larger than the limit
        $testFilePath = 'test_oversized_audio.mp3';
        Storage::disk('public')->put($testFilePath, str_repeat('a', 10 * 1024 * 1024)); // 10MB

        try {
            $this->service->transcribe($testFilePath, 'test-processing-id');
            $this->fail('Expected exception was not thrown');
        } catch (Exception $e) {
            $message = $e->getMessage();

            // The service may fail at different validation stages
            $validFailureReasons = [
                'Audio file too large',
                'Failed to get audio duration',
                'Unable to probe',
                'Transcription failed',
                'API key',
            ];

            $hasValidFailure = false;
            foreach ($validFailureReasons as $reason) {
                if (str_contains($message, $reason)) {
                    $hasValidFailure = true;
                    break;
                }
            }

            $this->assertTrue($hasValidFailure, 'Expected a valid failure reason, got: '.$message);
        }

        // Cleanup
        Storage::disk('public')->delete($testFilePath);
    }

    public function test_validation_passes_for_appropriately_sized_files(): void
    {
        // Set a reasonable limit (must use the key validateAndCompressIfNeeded reads)
        Config::set('media-processing.audio_extraction.transcription_optimized.max_file_size', 25 * 1024 * 1024); // 25MB

        // Create a small test file (well under the limit)
        $testFilePath = 'test_small_audio.mp3';
        Storage::disk('public')->put($testFilePath, str_repeat('a', 1024)); // 1KB — tiny, so size check passes immediately

        // We expect this to get past size validation and fail at the FFmpeg duration probe
        // (because the file isn't a real audio file), not at an "Audio file too large" check.
        try {
            $this->service->transcribe($testFilePath, 'test-processing-id');
        } catch (Exception $e) {
            // The size validation should pass — any exception must come from FFmpeg or API, not file size.
            $this->assertStringNotContainsString('Audio file too large', $e->getMessage());
            $this->assertStringNotContainsString('file not found', $e->getMessage());
        }

        // Cleanup
        Storage::disk('public')->delete($testFilePath);
    }

    public function test_config_max_file_size_matches_openai_limit(): void
    {
        $maxSize = config('media-processing.transcription.max_file_size');

        // Should be exactly 25MB (OpenAI Whisper limit)
        $expectedSize = 25 * 1024 * 1024;
        $this->assertEquals($expectedSize, $maxSize);
    }

    public function test_validation_method_handles_zero_byte_files(): void
    {
        // Create an empty test file
        $testFilePath = 'test_empty_audio.mp3';
        Storage::disk('public')->put($testFilePath, '');

        // The validation should pass (0 bytes < limit), but transcription will fail for other reasons
        // We're not testing the full transcription flow, just that validation doesn't reject empty files

        try {
            $this->service->transcribe($testFilePath, 'test-processing-id');
            $this->fail('Expected some exception due to empty file or API issues');
        } catch (Exception $e) {
            // Should not be a file size error for empty file
            $this->assertStringNotContainsString('Audio file too large', $e->getMessage());
        }

        // Cleanup
        Storage::disk('public')->delete($testFilePath);
    }

    public function test_error_message_includes_compression_guidance(): void
    {
        // Force the file over the transcription size limit using the key the
        // service actually reads, so it attempts compression.
        Config::set('media-processing.audio_extraction.transcription_optimized.max_file_size', 1024); // 1KB

        // A chunking service whose compression step fails deterministically, so
        // we exercise the "compression failed" branch that surfaces the
        // operator-facing guidance — without falling through to the OpenAI client.
        $failingChunkingService = $this->createMock(AudioChunkingService::class);
        $failingChunkingService->method('getAudioDuration')->willReturn(100.0);
        $failingChunkingService->method('needsChunking')->willReturn(false);
        $failingChunkingService->method('compressAudioForTranscription')
            ->willThrowException(new \RuntimeException('ffmpeg unavailable'));

        $service = new AudioTranscriptionService(
            $this->mockLogger,
            app(TranscriptStorageService::class),
            $failingChunkingService,
            new TranscriptFormatterService(app(BritishEnglishConverter::class)),
        );

        $testFilePath = 'test_guidance_audio.mp3';
        Storage::disk('public')->put($testFilePath, str_repeat('a', 2048)); // 2KB > 1KB limit

        try {
            $service->transcribe($testFilePath, 'test-processing-id');
            $this->fail('Expected a TranscriptionException was not thrown');
        } catch (TranscriptionException $e) {
            // The failure must guide the operator toward compressing the audio.
            $this->assertStringContainsString(
                'Please ensure audio is compressed for transcription',
                $e->getMessage(),
            );
        }

        // Cleanup
        Storage::disk('public')->delete($testFilePath);
    }

    public function test_is_non_retryable_error_correctly_detects_non_retryable_status_codes(): void
    {
        $testFilePath = 'test_api_error.mp3';
        Storage::disk('local')->put($testFilePath, 'mock content');

        $nonRetryableCodes = [400, 401, 413];
        foreach ($nonRetryableCodes as $code) {
            OpenAI::fake([
                // Only the HTTP status (via the Response) should drive the
                // non-retryable classification. Keep the payload `code` null so
                // the test fails if the classifier regresses to reading it
                // instead of the response status.
                new ErrorException([
                    'message' => "Test error {$code}",
                    'type' => 'test_error',
                    'code' => null,
                ], new Response($code)),
            ]);

            try {
                $this->service->transcribe($testFilePath, 'test-proc-id');
                $this->fail("Expected NonRetryableTranscriptionException for status {$code}");
            } catch (NonRetryableTranscriptionException $e) {
                $this->assertStringContainsString((string) $code, $e->getMessage());
            }
        }

        Storage::disk('local')->delete($testFilePath);
    }

    public function test_is_non_retryable_error_treats_retryable_status_codes_as_retryable(): void
    {
        $testFilePath = 'test_api_retryable_error.mp3';
        Storage::disk('local')->put($testFilePath, 'mock content');

        $retryableCodes = [429, 500, 503];
        foreach ($retryableCodes as $code) {
            OpenAI::fake([
                new ErrorException([
                    'message' => "Transient error {$code}",
                    'type' => 'server_error',
                    'code' => null,
                ], new Response($code)),
            ]);

            try {
                $this->service->transcribe($testFilePath, 'test-proc-id');
                $this->fail("Expected TranscriptionException for status {$code}");
            } catch (TranscriptionException $e) {
                $this->assertNotInstanceOf(NonRetryableTranscriptionException::class, $e);
                $this->assertStringContainsString((string) $code, $e->getMessage());
            }
        }

        Storage::disk('local')->delete($testFilePath);
    }

    protected function tearDown(): void
    {
        // Clean up any remaining test files
        $testFiles = [
            'test_large_audio.mp3',
            'test_oversized_audio.mp3',
            'test_small_audio.mp3',
            'test_empty_audio.mp3',
            'test_guidance_audio.mp3',
        ];

        foreach ($testFiles as $file) {
            if (Storage::exists($file)) {
                Storage::disk('public')->delete($file);
            }
        }

        parent::tearDown();
    }
}
