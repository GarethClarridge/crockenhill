<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Contracts\TranscriptionServiceInterface;
use App\Exceptions\TranscriptionException;
use App\Services\AudioChunkingService;
use App\Services\BritishEnglishConverter;
use App\Services\LocalWhisperTranscriptionService;
use App\Services\SermonProcessingLogger;
use App\Services\TranscriptFormatterService;
use App\Services\TranscriptStorageService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LocalWhisperTranscriptionServiceTest extends TestCase
{
    private LocalWhisperTranscriptionService $service;

    private AudioChunkingService $chunkingService;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        config(['media-processing.storage.sermon_disk' => 'local']);
        config(['media-processing.storage.transcript_disk' => 'local']);
        config(['media-processing.transcription.local_whisper_url' => 'http://whisper:8000']);
        config(['media-processing.transcription.local_whisper_model' => 'small']);
        config(['media-processing.transcription.local_whisper_timeout' => 60]);
        config(['media-processing.audio_extraction.transcription_optimized.max_file_size' => 25 * 1024 * 1024]);

        $logger = app(SermonProcessingLogger::class);
        $storageService = app(TranscriptStorageService::class);
        $formatter = new TranscriptFormatterService(app(BritishEnglishConverter::class));

        // Mock AudioChunkingService to skip FFprobe validation on fake audio files
        $this->chunkingService = Mockery::mock(AudioChunkingService::class);
        $this->chunkingService->shouldReceive('getAudioDuration')->andReturn(60.0);
        $this->chunkingService->shouldReceive('needsChunking')->andReturn(false);

        $this->service = new LocalWhisperTranscriptionService($logger, $storageService, $this->chunkingService, $formatter);
    }

    #[Test]
    public function it_implements_transcription_service_interface(): void
    {
        $this->assertInstanceOf(TranscriptionServiceInterface::class, $this->service);
    }

    #[Test]
    public function it_throws_exception_when_audio_file_not_found(): void
    {
        Http::fake();

        $this->expectException(TranscriptionException::class);
        $this->expectExceptionMessage('Audio file not found');

        $this->service->transcribe('nonexistent/audio.mp3', 'test-id');
    }

    #[Test]
    public function it_transcribes_audio_file_via_local_whisper_server(): void
    {
        $rawTranscript = 'This is a test sermon transcript with enough words and characters to pass validation checks.';

        Http::fake([
            'http://whisper:8000/v1/audio/transcriptions' => Http::response($rawTranscript, 200),
        ]);

        Storage::disk('local')->put('test-audio.mp3', str_repeat('x', 100));

        $result = $this->service->transcribe('test-audio.mp3', 'test-id');

        $this->assertIsString($result);
        $this->assertStringContainsString('This is a test sermon transcript', $result);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v1/audio/transcriptions');
        });
    }

    #[Test]
    public function it_sends_request_to_correct_whisper_endpoint(): void
    {
        $transcript = 'This sermon discusses Romans chapter eight and the sovereignty of God over all things.';

        Http::fake([
            'http://whisper:8000/v1/audio/transcriptions' => Http::response($transcript, 200),
        ]);

        Storage::disk('local')->put('sermon.mp3', str_repeat('x', 100));

        $this->service->transcribe('sermon.mp3', 'test-id');

        Http::assertSent(function ($request) {
            return $request->url() === 'http://whisper:8000/v1/audio/transcriptions'
                && $request->isMultipart();
        });
    }

    #[Test]
    public function it_throws_exception_on_server_error(): void
    {
        Http::fake([
            'http://whisper:8000/v1/audio/transcriptions' => Http::response('Internal Server Error', 500),
        ]);

        Storage::disk('local')->put('test-audio.mp3', str_repeat('x', 100));

        $this->expectException(TranscriptionException::class);
        $this->expectExceptionMessage('Local Whisper transcription failed: HTTP 500');

        $this->service->transcribe('test-audio.mp3', 'test-id');
    }

    #[Test]
    public function it_throws_exception_on_empty_transcript(): void
    {
        Http::fake([
            'http://whisper:8000/v1/audio/transcriptions' => Http::response('', 200),
        ]);

        Storage::disk('local')->put('test-audio.mp3', str_repeat('x', 100));

        $this->expectException(TranscriptionException::class);
        $this->expectExceptionMessage('Local Whisper returned an empty transcript');

        $this->service->transcribe('test-audio.mp3', 'test-id');
    }

    #[Test]
    public function it_throws_exception_on_invalid_transcript_content(): void
    {
        Http::fake([
            'http://whisper:8000/v1/audio/transcriptions' => Http::response('short', 200),
        ]);

        Storage::disk('local')->put('test-audio.mp3', str_repeat('x', 100));

        $this->expectException(TranscriptionException::class);
        $this->expectExceptionMessage('validation failed');

        $this->service->transcribe('test-audio.mp3', 'test-id');
    }

    #[Test]
    public function it_throws_exception_on_connection_failure(): void
    {
        Http::fake([
            'http://whisper:8000/*' => function () {
                throw new ConnectionException('Connection refused');
            },
        ]);

        Storage::disk('local')->put('test-audio.mp3', str_repeat('x', 100));

        $this->expectException(TranscriptionException::class);
        $this->expectExceptionMessage('Local Whisper connection failed');

        $this->service->transcribe('test-audio.mp3', 'test-id');
    }

    #[Test]
    public function it_can_store_and_retrieve_transcript(): void
    {
        $sermonId = 1;
        $transcript = "# Test Sermon\n\nThis is a test transcript with enough content.";

        $filePath = $this->service->storeTranscript($sermonId, $transcript);

        $this->assertEquals('transcripts/sermon_1.md', $filePath);
        $this->assertTrue($this->service->transcriptExists($sermonId));
        $this->assertEquals($transcript, $this->service->getTranscript($sermonId));
    }

    #[Test]
    public function it_can_delete_transcript(): void
    {
        $sermonId = 2;

        $this->service->storeTranscript($sermonId, 'Test transcript content');
        $this->assertTrue($this->service->transcriptExists($sermonId));

        $result = $this->service->deleteTranscript($sermonId);

        $this->assertTrue($result);
        $this->assertFalse($this->service->transcriptExists($sermonId));
    }

    #[Test]
    public function it_returns_null_for_nonexistent_transcript(): void
    {
        $this->assertNull($this->service->getTranscript(999));
        $this->assertFalse($this->service->transcriptExists(999));
    }

    #[Test]
    public function it_cleans_up_transcript_on_failure(): void
    {
        $sermonId = 3;
        $this->service->storeTranscript($sermonId, 'Test transcript content');
        $this->assertTrue($this->service->transcriptExists($sermonId));

        $this->service->cleanupOnFailure($sermonId);

        $this->assertFalse($this->service->transcriptExists($sermonId));
    }

    #[Test]
    public function it_returns_correct_transcript_path(): void
    {
        $this->assertEquals('transcripts/sermon_42.md', $this->service->getTranscriptPath(42));
    }

    #[Test]
    public function it_falls_back_to_public_disk_when_file_found_there(): void
    {
        $transcript = 'This sermon covers the sovereignty of God in all circumstances and trials.';

        Http::fake([
            'http://whisper:8000/v1/audio/transcriptions' => Http::response($transcript, 200),
        ]);

        // File is on public disk but sermon_disk is local
        Storage::disk('public')->put('fallback-audio.mp3', str_repeat('x', 100));

        $result = $this->service->transcribe('fallback-audio.mp3', 'test-id');

        $this->assertIsString($result);
        $this->assertStringContainsString('sovereignty of God', $result);
    }

    #[Test]
    public function it_transcribes_audio_from_an_absolute_path(): void
    {
        $transcript = 'This sermon explains the hope Christians have because Christ reigns over all things.';

        Http::fake([
            'http://whisper:8000/v1/audio/transcriptions' => Http::response($transcript, 200),
        ]);

        $tempDirectory = storage_path('app/temp/tests');
        if (! is_dir($tempDirectory)) {
            mkdir($tempDirectory, 0755, true);
        }

        $absolutePath = $tempDirectory.'/absolute-path-test.mp3';
        file_put_contents($absolutePath, str_repeat('x', 100));

        try {
            $result = $this->service->transcribe($absolutePath, 'test-id');
        } finally {
            if (file_exists($absolutePath)) {
                unlink($absolutePath);
            }
        }

        $this->assertIsString($result);
        $this->assertStringContainsString('Christ reigns over all things', $result);
    }
}
