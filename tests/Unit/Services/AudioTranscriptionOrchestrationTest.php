<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\NonRetryableTranscriptionException;
use App\Exceptions\TranscriptionException;
use App\Services\Media\Audio\AudioChunkingService;
use App\Services\Media\Audio\AudioTranscriptionService;
use App\Services\Media\Audio\TranscriptFormatterService;
use App\Services\Media\Audio\TranscriptStorageService;
use App\Services\Processing\SermonProcessingLogger;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Mockery;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Audio\TranscriptionResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AudioTranscriptionOrchestrationTest extends TestCase
{
    private AudioTranscriptionService $service;

    private mixed $logger;

    private mixed $chunkingService;

    private mixed $formatter;

    private mixed $storageService;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('s3');

        Config::set('media-processing.transcription.openai_api_key', 'test-key');
        Config::set('media-processing.storage.sermon_disk', 'local');
        Config::set('filesystems.disks.s3', ['driver' => 's3']);

        $this->logger = Mockery::mock(SermonProcessingLogger::class);
        $this->chunkingService = Mockery::mock(AudioChunkingService::class);
        $this->formatter = Mockery::mock(TranscriptFormatterService::class);
        $this->storageService = Mockery::mock(TranscriptStorageService::class);

        /** @var SermonProcessingLogger $logger */
        $logger = $this->logger;
        /** @var TranscriptStorageService $storageService */
        $storageService = $this->storageService;
        /** @var AudioChunkingService $chunkingService */
        $chunkingService = $this->chunkingService;
        /** @var TranscriptFormatterService $formatter */
        $formatter = $this->formatter;

        $this->service = new AudioTranscriptionService(
            $logger,
            $storageService,
            $chunkingService,
            $formatter
        );

        $this->logger->shouldIgnoreMissing();
    }

    #[Test]
    public function it_transcribes_a_local_file_successfully(): void
    {
        $audioPath = 'sermons/test-audio.mp3';
        Storage::disk('local')->put($audioPath, 'fake audio content');

        $this->chunkingService->shouldReceive('getAudioDuration')->andReturn(300.0);
        $this->chunkingService->shouldReceive('needsChunking')->andReturn(false);

        $transcript = str_repeat('This is a valid long transcript for testing orchestration. ', 10);

        OpenAI::fake([
            TranscriptionResponse::fake([
                'text' => $transcript,
            ]),
        ]);

        $this->formatter->shouldReceive('formatAsMarkdown')
            ->with($transcript)
            ->andReturn('# Transcribed text');

        $result = $this->service->transcribe($audioPath, 'proc-123', 'local');

        $this->assertSame('# Transcribed text', $result);
    }

    #[Test]
    public function it_downloads_s3_file_to_temp_before_transcription(): void
    {
        $audioPath = 'sermons/s3-audio.mp3';
        Storage::disk('s3')->put($audioPath, 's3 audio content');

        // Force S3 disk detection
        Config::set('filesystems.disks.s3.driver', 's3');

        // Capture the temp path that will be used
        $tempPathUsed = null;

        $this->chunkingService->shouldReceive('getAudioDuration')
            ->with(Mockery::on(function ($path) use (&$tempPathUsed) {
                $tempPathUsed = $path;

                return str_contains($path, 'temp/') && str_contains($path, 's3-audio.mp3');
            }))
            ->andReturn(300.0);

        $this->chunkingService->shouldReceive('needsChunking')->andReturn(false);

        $transcript = str_repeat('This is a valid long transcript for testing orchestration. ', 10);

        OpenAI::fake([
            TranscriptionResponse::fake(['text' => $transcript]),
        ]);

        $this->formatter->shouldReceive('formatAsMarkdown')->andReturn('markdown');

        $this->service->transcribe($audioPath, 'proc-s3', 's3');

        $this->assertNotNull($tempPathUsed);
        $this->assertFileDoesNotExist($tempPathUsed, 'Temp file should be cleaned up after transcription');
    }

    #[Test]
    public function it_falls_back_to_public_disk_if_file_not_found_on_primary_disk(): void
    {
        $audioPath = 'sermons/fallback.mp3';
        // Put it on public, but we'll ask for it on 'local'
        Storage::disk('public')->put($audioPath, 'public content');

        $this->chunkingService->shouldReceive('getAudioDuration')->andReturn(100.0);
        $this->chunkingService->shouldReceive('needsChunking')->andReturn(false);

        $transcript = str_repeat('This is a valid long transcript for testing orchestration. ', 10);

        OpenAI::fake([
            TranscriptionResponse::fake(['text' => $transcript]),
        ]);

        $this->formatter->shouldReceive('formatAsMarkdown')->andReturn('markdown');

        $this->logger->shouldReceive('logProcessingStep')
            ->with('proc-fallback', 'audio_transcription', 'started', Mockery::on(function ($context) {
                return $context['disk'] === 'public';
            }));

        // Ask for 'local', but it should find it on 'public'
        $result = $this->service->transcribe($audioPath, 'proc-fallback', 'local');

        $this->assertSame('markdown', $result);
    }

    #[Test]
    public function it_handles_absolute_paths_correctly(): void
    {
        // Use a path within the app's storage to avoid "system temporary directory" warning/error
        $tempFile = storage_path('app/temp/abs_test_'.uniqid().'.mp3');
        if (! is_dir(dirname($tempFile))) {
            mkdir(dirname($tempFile), 0755, true);
        }

        // Use a valid MP3 header to satisfy FFProbe
        $mp3Header = "\xFF\xFB\x90\x44";
        file_put_contents($tempFile, $mp3Header.str_repeat('a', 1000));

        $this->chunkingService->shouldReceive('getAudioDuration')->with($tempFile)->andReturn(100.0);
        $this->chunkingService->shouldReceive('needsChunking')->andReturn(false);

        $transcript = str_repeat('This is a valid long transcript for testing orchestration. ', 10);

        OpenAI::fake([
            TranscriptionResponse::fake(['text' => $transcript]),
        ]);

        $this->formatter->shouldReceive('formatAsMarkdown')->andReturn('markdown');

        $result = $this->service->transcribe($tempFile, 'proc-abs');

        $this->assertSame('markdown', $result);
        unlink($tempFile);
    }

    #[Test]
    public function it_compresses_file_if_too_large_and_cleans_up_compressed_version(): void
    {
        $audioPath = 'sermons/large.mp3';
        Storage::disk('local')->put($audioPath, str_repeat('x', 1024)); // size doesn't matter for mock, but we need the file

        $fullPath = Storage::disk('local')->path($audioPath);

        // Mock config for max size to be very small
        Config::set('media-processing.audio_extraction.transcription_optimized.max_file_size', 512);

        $compressedPath = storage_path('app/temp/compressed.mp3');
        if (! is_dir(dirname($compressedPath))) {
            mkdir(dirname($compressedPath), 0755, true);
        }
        file_put_contents($compressedPath, 'compressed content');

        $this->chunkingService->shouldReceive('compressAudioForTranscription')
            ->with($fullPath, 'proc-large')
            ->andReturn($compressedPath);

        $this->chunkingService->shouldReceive('getAudioDuration')->andReturn(300.0);
        $this->chunkingService->shouldReceive('needsChunking')->andReturn(false);

        $transcript = str_repeat('This is a valid long transcript for testing orchestration. ', 10);

        OpenAI::fake([
            TranscriptionResponse::fake(['text' => $transcript]),
        ]);

        $this->formatter->shouldReceive('formatAsMarkdown')->andReturn('markdown');

        $this->service->transcribe($audioPath, 'proc-large', 'local');

        $this->assertFileDoesNotExist($compressedPath, 'Compressed file should be cleaned up');
    }

    #[Test]
    public function it_cleans_up_all_temp_files_on_exception(): void
    {
        $audioPath = 'sermons/s3-large.mp3';
        // Put 100 bytes on S3
        Storage::disk('s3')->put($audioPath, str_repeat('s', 100));

        // Set max size to 50 to trigger compression (100 > 50)
        Config::set('media-processing.audio_extraction.transcription_optimized.max_file_size', 50);

        $tempDownloadPath = null;
        $compressedPath = storage_path('app/temp/fail-compressed.mp3');
        if (! is_dir(dirname($compressedPath))) {
            mkdir(dirname($compressedPath), 0755, true);
        }
        // Compressed file is 40 bytes (40 <= 50, passes validation)
        file_put_contents($compressedPath, str_repeat('c', 40));

        $this->chunkingService->shouldReceive('compressAudioForTranscription')
            ->andReturnUsing(function ($path) use (&$tempDownloadPath, $compressedPath) {
                $tempDownloadPath = $path;

                return $compressedPath;
            });

        // Trigger failure in getAudioDuration
        $this->chunkingService->shouldReceive('getAudioDuration')
            ->andThrow(new \RuntimeException('Something went wrong'));

        try {
            // Need to wrap in a try-finally or ensure transcribe() has internal try-finally
            // that handles these paths.
            $this->service->transcribe($audioPath, 'proc-fail', 's3');
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('Something went wrong', $e->getMessage());
        }

        $this->assertNotNull($tempDownloadPath);
        $this->assertFileDoesNotExist($tempDownloadPath, 'Temp S3 download should be cleaned up on failure');
        $this->assertFileDoesNotExist($compressedPath, 'Compressed file should be cleaned up on failure');
    }

    #[Test]
    public function it_cleans_up_chunks_after_successful_chunked_transcription(): void
    {
        $audioPath = 'sermons/chunk-test.mp3';
        Storage::disk('local')->put($audioPath, 'content');

        $this->chunkingService->shouldReceive('getAudioDuration')->andReturn(3600.0);
        $this->chunkingService->shouldReceive('needsChunking')->with(3600.0)->andReturn(true);

        $chunks = [
            storage_path('app/temp/chunk1.mp3'),
            storage_path('app/temp/chunk2.mp3'),
        ];

        foreach ($chunks as $chunk) {
            if (! is_dir(dirname($chunk))) {
                mkdir(dirname($chunk), 0755, true);
            }
            file_put_contents($chunk, 'chunk content');
        }

        $this->chunkingService->shouldReceive('createAudioChunks')->andReturn($chunks);

        $transcript = str_repeat('Valid long transcript content for chunks. ', 10);
        OpenAI::fake([
            TranscriptionResponse::fake(['text' => $transcript]),
            TranscriptionResponse::fake(['text' => $transcript]),
        ]);

        $this->chunkingService->shouldReceive('getChunkDurationMinutes')->andReturn(20);
        $this->chunkingService->shouldReceive('getChunkOverlapSeconds')->andReturn(30);

        $this->chunkingService->shouldReceive('reassembleTranscripts')->andReturn('reassembled');
        $this->formatter->shouldReceive('formatAsMarkdown')->andReturn('markdown');

        // Capture cleanup call
        $this->chunkingService->shouldReceive('cleanupChunkFiles')
            ->once()
            ->with($chunks, 'proc-chunks');

        $this->service->transcribe($audioPath, 'proc-chunks', 'local');
    }

    #[Test]
    public function it_identifies_non_retryable_errors(): void
    {
        $audioPath = 'sermons/test.mp3';
        Storage::disk('local')->put($audioPath, 'content');

        $this->chunkingService->shouldReceive('getAudioDuration')->andReturn(100.0);
        $this->chunkingService->shouldReceive('needsChunking')->andReturn(false);

        // Force an OpenAI 401 Unauthorized error (often non-retryable/deterministic misconfig)
        OpenAI::fake([
            new ErrorException(['message' => 'unauthorized', 'type' => 'invalid_request_error', 'code' => null], new Response(401)),
        ]);

        $this->expectException(NonRetryableTranscriptionException::class);
        $this->expectExceptionMessage('Transcription failed (non-retryable)');

        $this->service->transcribe($audioPath, 'proc-err', 'local');
    }

    #[Test]
    public function it_identifies_retryable_api_errors(): void
    {
        $audioPath = 'sermons/test.mp3';
        Storage::disk('local')->put($audioPath, 'content');

        $this->chunkingService->shouldReceive('getAudioDuration')->andReturn(100.0);
        $this->chunkingService->shouldReceive('needsChunking')->andReturn(false);

        // Force an OpenAI 500 Server Error
        OpenAI::fake([
            new ErrorException(['message' => 'internal error', 'type' => 'server_error', 'code' => null], new Response(500)),
        ]);

        $this->expectException(TranscriptionException::class);
        $this->expectExceptionMessage('Transcription failed (retryable API error)');

        $this->service->transcribe($audioPath, 'proc-err', 'local');
    }

    #[Test]
    public function it_fails_when_transcript_is_too_short(): void
    {
        $audioPath = 'sermons/test.mp3';
        Storage::disk('local')->put($audioPath, 'content');

        $this->chunkingService->shouldReceive('getAudioDuration')->andReturn(100.0);
        $this->chunkingService->shouldReceive('needsChunking')->andReturn(false);

        OpenAI::fake([
            TranscriptionResponse::fake(['text' => 'Too short']),
        ]);

        $this->expectException(TranscriptionException::class);
        $this->expectExceptionMessage('Transcript validation failed');

        $this->service->transcribe($audioPath, 'proc-err', 'local');
    }
}
