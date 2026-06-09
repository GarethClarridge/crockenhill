<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Media\Audio\AudioCompressionService;
use App\Services\Processing\StorageAdapterHelper;
use FFMpeg\FFMpeg;
use FFMpeg\Format\Audio\Mp3;
use FFMpeg\Media\Audio;
use FFMpeg\Media\Video;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

class AudioCompressionServiceTest extends TestCase
{
    private AudioCompressionService $service;

    private MockObject&StorageAdapterHelper $storageHelper;

    private MockObject&FFMpeg $ffmpeg;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        Config::set('media-processing.ffmpeg.ffmpeg_path', '/usr/bin/ffmpeg');
        Config::set('media-processing.ffmpeg.ffprobe_path', '/usr/bin/ffprobe');
        Config::set('media-processing.storage.temp_disk', 'local');
        Config::set('media-processing.storage.sermon_disk', 'public');
        Config::set('media-processing.storage.paths.audio', 'sermons/audio');
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

        $this->ffmpeg = $this->createMock(FFMpeg::class);
        $this->storageHelper = $this->createMock(StorageAdapterHelper::class);
        $this->storageHelper->method('createFFMpeg')->willReturn($this->ffmpeg);

        $this->service = new AudioCompressionService($this->storageHelper);
    }

    #[Test]
    public function it_can_be_instantiated_in_test_environment(): void
    {
        $this->assertInstanceOf(AudioCompressionService::class, $this->service);
    }

    #[Test]
    public function it_extracts_audio_and_validates_size_within_limit(): void
    {
        Storage::disk('local')->makeDirectory('temp');

        $inputVideo = Storage::disk('local')->path('test_'.Str::random(8).'.mp4');
        $segment = (object) ['startTime' => 0, 'endTime' => 10];
        $outputFilename = 'output.mp3';

        $this->storageHelper->expects($this->once())
            ->method('getProcessingOutputPath')
            ->willReturn([
                'processing_path' => Storage::disk('local')->path('temp/output.mp3'),
                'permanent_path' => 'sermons/audio/output.mp3',
                'use_temp_processing' => false,
            ]);

        $video = $this->createMock(Video::class);
        $this->ffmpeg->expects($this->once())
            ->method('open')
            ->with($inputVideo)
            ->willReturn($video);

        $video->expects($this->once())
            ->method('clip')
            ->willReturnSelf();

        $video->expects($this->once())
            ->method('save')
            ->with($this->isInstanceOf(Mp3::class), Storage::disk('local')->path('temp/output.mp3'))
            ->willReturnCallback(function () {
                // Simulate FFmpeg creating the file
                Storage::disk('local')->put('temp/output.mp3', str_repeat('x', 1024));

                return $this->createMock(Video::class);
            });

        // Create the input file so file_exists check passes
        file_put_contents($inputVideo, 'dummy content');

        try {
            $result = $this->service->extractOptimizedAudio($inputVideo, $segment, $outputFilename);

            $this->assertTrue($result['valid_for_transcription']);
            $this->assertEquals(1024, $result['final_size']);
            $this->assertFalse($result['compression_applied']);
        } finally {
            @unlink($inputVideo);
        }
    }

    #[Test]
    public function it_applies_fallback_compression_when_file_exceeds_limit(): void
    {
        Storage::disk('local')->makeDirectory('temp');

        Config::set('media-processing.audio_extraction.transcription_optimized.max_file_size', 500);

        $inputVideo = Storage::disk('local')->path('test_'.Str::random(8).'.mp4');
        $segment = (object) ['startTime' => 0, 'endTime' => 10];
        $outputFilename = 'output.mp3';
        $processingPath = Storage::disk('local')->path('temp/output.mp3');
        $compressedPath = Storage::disk('local')->path('temp/output_compressed.mp3');

        $this->storageHelper->method('getProcessingOutputPath')
            ->willReturn([
                'processing_path' => $processingPath,
                'permanent_path' => 'sermons/audio/output.mp3',
                'use_temp_processing' => false,
            ]);

        $video = $this->createMock(Video::class);
        $audio = $this->createMock(Audio::class);

        $this->ffmpeg->expects($this->exactly(2))
            ->method('open')
            ->willReturnMap([
                [$inputVideo, $video],
                [$processingPath, $audio],
            ]);

        $video->method('clip')->willReturnSelf();
        $video->method('save')->willReturnCallback(function () use ($processingPath) {
            file_put_contents($processingPath, str_repeat('x', 1000)); // Exceeds 500 limit

            return $this->createMock(Video::class);
        });

        $audio->expects($this->once())
            ->method('save')
            ->with($this->isInstanceOf(Mp3::class), $compressedPath)
            ->willReturnCallback(function () use ($compressedPath) {
                file_put_contents($compressedPath, str_repeat('x', 400)); // Within 500 limit

                return $this->createMock(Audio::class);
            });

        file_put_contents($inputVideo, 'dummy content');

        try {
            $result = $this->service->extractOptimizedAudio($inputVideo, $segment, $outputFilename);

            $this->assertTrue($result['valid_for_transcription']);
            $this->assertEquals(400, $result['final_size']);
            $this->assertTrue($result['compression_applied']);
            $this->assertEquals(1000 / 400, $result['compression_ratio']);
        } finally {
            @unlink($inputVideo);
        }
    }

    #[Test]
    public function it_rejects_audio_when_even_fallback_compression_is_too_large(): void
    {
        Storage::disk('local')->makeDirectory('temp');

        Config::set('media-processing.audio_extraction.transcription_optimized.max_file_size', 100);

        $inputVideo = Storage::disk('local')->path('test_'.Str::random(8).'.mp4');
        $segment = (object) ['startTime' => 0, 'endTime' => 10];
        $outputFilename = 'output.mp3';
        $processingPath = Storage::disk('local')->path('temp/output.mp3');
        $compressedPath = Storage::disk('local')->path('temp/output_compressed.mp3');

        $this->storageHelper->method('getProcessingOutputPath')
            ->willReturn([
                'processing_path' => $processingPath,
                'permanent_path' => 'sermons/audio/output.mp3',
                'use_temp_processing' => false,
            ]);

        $video = $this->createMock(Video::class);
        $audio = $this->createMock(Audio::class);

        $this->ffmpeg->method('open')->willReturnMap([
            [$inputVideo, $video],
            [$processingPath, $audio],
        ]);

        $video->method('clip')->willReturnSelf();
        $video->method('save')->willReturnCallback(function () use ($processingPath) {
            file_put_contents($processingPath, str_repeat('x', 500));

            return $this->createMock(Video::class);
        });

        $audio->method('save')->willReturnCallback(function () use ($compressedPath) {
            file_put_contents($compressedPath, str_repeat('x', 200)); // Still exceeds 100 limit

            return $this->createMock(Audio::class);
        });

        file_put_contents($inputVideo, 'dummy content');

        try {
            $result = $this->service->extractOptimizedAudio($inputVideo, $segment, $outputFilename);

            $this->assertFalse($result['valid_for_transcription']);
            $this->assertEquals(200, $result['final_size']);
        } finally {
            @unlink($inputVideo);
        }
    }

    #[Test]
    public function it_rejects_zero_byte_audio_file(): void
    {
        Storage::disk('local')->makeDirectory('temp');

        $inputVideo = Storage::disk('local')->path('test_'.Str::random(8).'.mp4');
        $segment = (object) ['startTime' => 0, 'endTime' => 10];
        $outputFilename = 'output.mp3';
        $processingPath = Storage::disk('local')->path('temp/output.mp3');
        $compressedPath = Storage::disk('local')->path('temp/output_compressed.mp3');

        $this->storageHelper->method('getProcessingOutputPath')
            ->willReturn([
                'processing_path' => $processingPath,
                'permanent_path' => 'sermons/audio/output.mp3',
                'use_temp_processing' => false,
            ]);

        $video = $this->createMock(Video::class);
        $audio = $this->createMock(Audio::class);

        $this->ffmpeg->method('open')->willReturnMap([
            [$inputVideo, $video],
            [$processingPath, $audio],
        ]);

        $video->method('clip')->willReturnSelf();
        $video->method('save')->willReturnCallback(function () {
            // Simulate FFmpeg creating an empty file (0 bytes)
            Storage::disk('local')->put('temp/output.mp3', '');

            return $this->createMock(Video::class);
        });

        $audio->method('save')->willReturnCallback(function () {
            Storage::disk('local')->put('temp/output_compressed.mp3', '');

            return $this->createMock(Audio::class);
        });

        file_put_contents($inputVideo, 'dummy content');

        try {
            $result = $this->service->extractOptimizedAudio($inputVideo, $segment, $outputFilename);

            $this->assertFalse($result['valid_for_transcription']);
            $this->assertEquals(0, $result['final_size']);
        } finally {
            @unlink($inputVideo);
        }
    }
}
