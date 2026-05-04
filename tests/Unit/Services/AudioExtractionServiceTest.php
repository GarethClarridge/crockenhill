<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\InvalidFileException;
use App\Services\AudioExtractionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AudioExtractionServiceTest extends TestCase
{
    private AudioExtractionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('media-processing.ffmpeg.ffmpeg_path', '/usr/bin/ffmpeg');
        Config::set('media-processing.ffmpeg.ffprobe_path', '/usr/bin/ffprobe');
        Config::set('media-processing.audio_extraction.transcription_optimized', [
            'bitrate' => 48,
            'sample_rate' => 16000,
            'channels' => 1,
            'max_file_size' => 25 * 1024 * 1024,
        ]);
        Config::set('media-processing.audio_extraction.fallback_compression', [
            'bitrate' => 32,
            'channels' => 1,
        ]);

        $this->service = $this->app->make(AudioExtractionService::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ---- Instantiation ----

    #[Test]
    public function it_can_be_instantiated(): void
    {
        $this->assertInstanceOf(AudioExtractionService::class, $this->service);
    }

    // ---- validateAudioFile ----

    #[Test]
    public function it_validates_a_valid_mp3_file(): void
    {
        $file = UploadedFile::fake()->create('sermon.mp3', 1024, 'audio/mpeg');

        $this->service->validateAudioFile($file);
        $this->assertTrue(true); // No exception thrown
    }

    #[Test]
    public function it_validates_a_valid_wav_file(): void
    {
        $file = UploadedFile::fake()->create('sermon.wav', 2048, 'audio/wav');

        $this->service->validateAudioFile($file);
        $this->assertTrue(true);
    }

    #[Test]
    public function it_validates_a_valid_m4a_file(): void
    {
        $file = UploadedFile::fake()->create('sermon.m4a', 1024, 'audio/m4a');

        $this->service->validateAudioFile($file);
        $this->assertTrue(true);
    }

    #[Test]
    public function it_rejects_file_with_disallowed_extension(): void
    {
        $file = UploadedFile::fake()->create('sermon.ogg', 1024, 'audio/mpeg');

        $this->expectException(InvalidFileException::class);
        $this->expectExceptionMessage('Invalid file extension');

        $this->service->validateAudioFile($file);
    }

    #[Test]
    public function it_rejects_file_with_disallowed_mime_type(): void
    {
        $file = UploadedFile::fake()->create('sermon.mp3', 1024, 'audio/ogg');

        $this->expectException(InvalidFileException::class);
        $this->expectExceptionMessage('Invalid file type');

        $this->service->validateAudioFile($file);
    }

    #[Test]
    public function it_rejects_file_exceeding_max_size(): void
    {
        Config::set('media-processing.types.audio.max_file_size', 1024); // 1KB

        $file = UploadedFile::fake()->create('sermon.mp3', 10, 'audio/mpeg'); // 10KB

        $this->expectException(InvalidFileException::class);
        $this->expectExceptionMessage('exceeds maximum limit');

        $this->service->validateAudioFile($file);
    }

    #[Test]
    public function it_rejects_file_with_pdf_extension(): void
    {
        $file = UploadedFile::fake()->create('document.pdf', 1024, 'audio/mpeg');

        $this->expectException(InvalidFileException::class);
        $this->expectExceptionMessage('Invalid file extension');

        $this->service->validateAudioFile($file);
    }

    #[Test]
    public function it_accepts_mp4_extension_with_audio_mime(): void
    {
        $file = UploadedFile::fake()->create('sermon.mp4', 1024, 'audio/mp4');

        $this->service->validateAudioFile($file);
        $this->assertTrue(true);
    }

    // ---- extractFromVideo (requires FFmpeg, test error handling) ----

    #[Test]
    public function it_logs_error_and_rethrows_when_extract_from_video_fails(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'Failed to extract audio from video'
                    && isset($context['video_path'])
                    && isset($context['error']);
            });

        // FFMpeg::create will fail because the binaries don't exist in test
        $this->expectException(\Exception::class);

        $this->service->extractFromVideo('/nonexistent/video.mp4', 120.0);
    }

    // ---- compressForTranscription (requires FFmpeg, test error handling) ----

    #[Test]
    public function it_logs_error_and_rethrows_when_compression_fails(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'Failed to compress audio file'
                    && isset($context['input_path'])
                    && isset($context['error']);
            });

        $this->expectException(\Exception::class);

        $this->service->compressForTranscription('/nonexistent/audio.mp3');
    }
}
