<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\InvalidFileException;
use App\Services\Media\Audio\AudioExtractionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
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

        $this->expectNotToPerformAssertions();

        $this->service->validateAudioFile($file);
    }

    #[Test]
    public function it_validates_a_valid_wav_file(): void
    {
        $file = UploadedFile::fake()->create('sermon.wav', 2048, 'audio/wav');

        $this->expectNotToPerformAssertions();

        $this->service->validateAudioFile($file);
    }

    #[Test]
    public function it_validates_a_valid_m4a_file(): void
    {
        $file = UploadedFile::fake()->create('sermon.m4a', 1024, 'audio/m4a');

        $this->expectNotToPerformAssertions();

        $this->service->validateAudioFile($file);
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

        $this->expectNotToPerformAssertions();

        $this->service->validateAudioFile($file);
    }
}
