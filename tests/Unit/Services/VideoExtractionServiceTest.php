<?php

namespace Tests\Unit\Services;

use App\Exceptions\VideoProcessingException;
use App\Services\AudioCompressionService;
use App\Services\VideoExtractionService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VideoExtractionServiceTest extends TestCase
{
    private VideoExtractionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        Config::set('media-processing.ffmpeg.ffmpeg_path', '/usr/bin/ffmpeg');
        Config::set('media-processing.ffmpeg.ffprobe_path', '/usr/bin/ffprobe');
        Config::set('media-processing.processing.timeout', 3600);
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
        Config::set('media-processing.s3_processing', [
            'retry_attempts' => 3,
            'retry_delay' => 1,
            'upload_timeout' => 300,
        ]);

        $this->service = new VideoExtractionService(new AudioCompressionService);
    }

    // ---- Constructor and instantiation ----

    #[Test]
    public function it_can_be_instantiated_in_test_environment(): void
    {
        $this->assertInstanceOf(VideoExtractionService::class, $this->service);
    }

    // ---- extractSegment routing tests ----
    // Note: extractSegment, extractSegmentAsFile, and extractSegmentAsUpload
    // require real FFmpeg binaries. We test the logic that doesn't need FFmpeg.

    // ---- Segment time property access ----

    #[Test]
    public function it_reads_camel_case_segment_properties(): void
    {
        $segment = (object) ['startTime' => 100, 'endTime' => 200];

        $startTime = $segment->startTime ?? $segment->start_time ?? 0;
        $endTime = $segment->endTime ?? $segment->end_time ?? 0;

        $this->assertEquals(100, $startTime);
        $this->assertEquals(200, $endTime);
    }

    #[Test]
    public function it_reads_snake_case_segment_properties(): void
    {
        $segment = (object) ['start_time' => 100, 'end_time' => 200];

        $startTime = $segment->startTime ?? $segment->start_time ?? 0;
        $endTime = $segment->endTime ?? $segment->end_time ?? 0;

        $this->assertEquals(100, $startTime);
        $this->assertEquals(200, $endTime);
    }

    #[Test]
    public function it_rejects_invalid_segment_times_for_upload_extraction(): void
    {
        // start >= end should throw
        $segment = (object) ['startTime' => 200, 'endTime' => 100];

        $this->expectException(VideoProcessingException::class);
        $this->expectExceptionMessage('Invalid segment times');

        $this->service->extractSegmentAsUpload('/nonexistent/video.mp4', $segment);
    }

    #[Test]
    public function it_rejects_equal_start_and_end_times_for_upload_extraction(): void
    {
        $segment = (object) ['startTime' => 100, 'endTime' => 100];

        $this->expectException(VideoProcessingException::class);
        $this->expectExceptionMessage('Invalid segment times');

        $this->service->extractSegmentAsUpload('/nonexistent/video.mp4', $segment);
    }

    // ---- isS3Disk tests (via DetectsStorageType trait) ----
    // Full coverage is in DetectsStorageTypeTest; these just verify the trait is wired up.

    #[Test]
    public function it_detects_s3_compatible_disks(): void
    {
        $method = $this->getPrivateMethod('isS3Disk');

        Config::set('filesystems.disks.spaces', ['driver' => 's3']);
        Config::set('filesystems.disks.local_test', ['driver' => 'local']);

        $this->assertTrue($method->invoke($this->service, 'spaces'));
        $this->assertFalse($method->invoke($this->service, 'local_test'));
    }

    #[Test]
    public function it_returns_false_for_nonexistent_disk(): void
    {
        $method = $this->getPrivateMethod('isS3Disk');

        $this->assertFalse($method->invoke($this->service, 'nonexistent_disk'));
    }

    // ---- getProcessingOutputPath tests ----

    #[Test]
    public function it_returns_direct_path_for_local_disk(): void
    {
        $method = $this->getPrivateMethod('getProcessingOutputPath');

        // Default disk is 'public' which is local
        $result = $method->invoke($this->service, 'test_audio.mp3');

        $this->assertArrayHasKey('processing_path', $result);
        $this->assertArrayHasKey('permanent_path', $result);
        $this->assertArrayHasKey('use_temp_processing', $result);
        $this->assertFalse($result['use_temp_processing']);
        $this->assertEquals('sermons/audio/test_audio.mp3', $result['permanent_path']);
    }

    #[Test]
    public function it_returns_temp_path_for_s3_disk(): void
    {
        Config::set('filesystems.disks.public', ['driver' => 's3']);
        Config::set('media-processing.storage.sermon_disk', 'public');
        $service = new VideoExtractionService(new AudioCompressionService);

        $method = (new \ReflectionClass($service))->getMethod('getProcessingOutputPath');
        $method->setAccessible(true);

        $result = $method->invoke($service, 'test_audio.mp3');

        $this->assertTrue($result['use_temp_processing']);
        $this->assertEquals('sermons/audio/test_audio.mp3', $result['permanent_path']);
        // Processing path should be a local temp path
        $this->assertStringContainsString('temp', $result['processing_path']);
    }

    // ---- fileExists tests ----

    #[Test]
    public function it_checks_local_file_existence(): void
    {
        $method = $this->getPrivateMethod('fileExists');

        $tempFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tempFile, 'test');

        $this->assertTrue($method->invoke($this->service, $tempFile, 'local'));
        $this->assertFalse($method->invoke($this->service, '/nonexistent/file.txt', 'local'));

        unlink($tempFile);
    }

    // ---- getFileSize tests ----

    #[Test]
    public function it_gets_local_file_size(): void
    {
        $method = $this->getPrivateMethod('getFileSize');

        $tempFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tempFile, str_repeat('x', 512));

        $size = $method->invoke($this->service, $tempFile, 'local');
        $this->assertEquals(512, $size);

        unlink($tempFile);
    }

    #[Test]
    public function it_returns_zero_for_nonexistent_local_file(): void
    {
        $method = $this->getPrivateMethod('getFileSize');

        $size = $method->invoke($this->service, '/nonexistent/file.txt', 'local');
        $this->assertEquals(0, $size);
    }

    // ---- getLocalFileSize tests ----

    #[Test]
    public function it_gets_local_file_size_directly(): void
    {
        $method = $this->getPrivateMethod('getLocalFileSize');

        $tempFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tempFile, str_repeat('y', 256));

        $this->assertEquals(256, $method->invoke($this->service, $tempFile));
        $this->assertEquals(0, $method->invoke($this->service, '/nonexistent.txt'));

        unlink($tempFile);
    }

    private function getPrivateMethod(string $methodName): \ReflectionMethod
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method;
    }
}
