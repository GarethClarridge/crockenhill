<?php

namespace Tests\Unit\Services;

use App\Services\AudioCompressionService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AudioCompressionServiceTest extends TestCase
{
    private AudioCompressionService $service;

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

        $this->service = app(AudioCompressionService::class);
    }

    // ---- Constructor and instantiation ----

    #[Test]
    public function it_can_be_instantiated_in_test_environment(): void
    {
        $this->assertInstanceOf(AudioCompressionService::class, $this->service);
    }

    // ---- validateAudioFileSize tests ----

    #[Test]
    public function it_validates_audio_file_within_size_limit(): void
    {
        $method = $this->getPrivateMethod('validateAudioFileSize');

        $tempFile = tempnam(sys_get_temp_dir(), 'test_audio');
        file_put_contents($tempFile, str_repeat('x', 1024)); // 1KB

        $result = $method->invoke($this->service, $tempFile);

        $this->assertTrue($result['valid']);
        $this->assertEquals(1024, $result['file_size']);
        $this->assertEquals(25 * 1024 * 1024, $result['max_size']);

        unlink($tempFile);
    }

    #[Test]
    public function it_rejects_audio_file_exceeding_size_limit(): void
    {
        // Temporarily set a very small max size
        Config::set('media-processing.audio_extraction.transcription_optimized.max_file_size', 100);
        $service = app(AudioCompressionService::class);
        $validateMethod = (new \ReflectionClass($service))->getMethod('validateAudioFileSize');
        $validateMethod->setAccessible(true);

        $tempFile = tempnam(sys_get_temp_dir(), 'test_audio');
        file_put_contents($tempFile, str_repeat('x', 200)); // 200 bytes > 100 limit

        $result = $validateMethod->invoke($service, $tempFile);

        $this->assertFalse($result['valid']);

        unlink($tempFile);
    }

    #[Test]
    public function it_rejects_zero_byte_audio_file(): void
    {
        $method = $this->getPrivateMethod('validateAudioFileSize');

        $tempFile = tempnam(sys_get_temp_dir(), 'test_audio');
        // File exists but is empty (0 bytes)

        $result = $method->invoke($this->service, $tempFile);

        $this->assertFalse($result['valid']);
        $this->assertEquals(0, $result['file_size']);

        unlink($tempFile);
    }

    #[Test]
    public function it_returns_size_in_megabytes(): void
    {
        $method = $this->getPrivateMethod('validateAudioFileSize');

        $tempFile = tempnam(sys_get_temp_dir(), 'test_audio');
        file_put_contents($tempFile, str_repeat('x', 1024 * 1024)); // 1MB

        $result = $method->invoke($this->service, $tempFile);

        $this->assertEqualsWithDelta(1.0, $result['size_mb'], 0.1);
        $this->assertEqualsWithDelta(25.0, $result['max_size_mb'], 0.1);

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
