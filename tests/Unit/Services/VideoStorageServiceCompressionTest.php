<?php

namespace Tests\Unit\Services;

use App\Data\LivestreamSegment;
use App\Services\VideoStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class VideoStorageServiceCompressionTest extends TestCase
{
    use RefreshDatabase;

    protected VideoStorageService $service;

    protected string $testVideoPath;

    protected LivestreamSegment $testSegment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new VideoStorageService;

        // Create a mock segment for testing
        $this->testSegment = new LivestreamSegment(
            startTime: 0.0,
            endTime: 60.0, // 1 minute segment
            duration: 60.0,
            classification: 'speech',
            avgRms: -20.0,
            peakRms: -10.0,
            isSermonCandidate: true,
            segmentOrder: 1
        );

        // Mock video path for testing
        $this->testVideoPath = storage_path('app/test_video.mp4');
    }

    public function test_validate_audio_file_size_returns_valid_for_small_file(): void
    {
        // Create a small test file
        $testFilePath = storage_path('app/small_test.mp3');
        file_put_contents($testFilePath, str_repeat('a', 1024 * 1024)); // 1MB

        $result = $this->service->validateAudioFileSize($testFilePath);

        $this->assertTrue($result['valid']);
        $this->assertEquals(1024 * 1024, $result['file_size']);
        $this->assertEquals(1.0, $result['size_mb']);

        // Cleanup
        unlink($testFilePath);
    }

    public function test_validate_audio_file_size_returns_invalid_for_large_file(): void
    {
        // Mock config to set a smaller limit for testing
        Config::set('livestream-processing.audio_extraction.transcription_optimized.max_file_size', 5 * 1024 * 1024); // 5MB

        // Create a large test file
        $testFilePath = storage_path('app/large_test.mp3');
        file_put_contents($testFilePath, str_repeat('a', 10 * 1024 * 1024)); // 10MB

        $result = $this->service->validateAudioFileSize($testFilePath);

        $this->assertFalse($result['valid']);
        $this->assertEquals(10 * 1024 * 1024, $result['file_size']);
        $this->assertEquals(10.0, $result['size_mb']);
        $this->assertEquals(5.0, $result['max_size_mb']);

        // Cleanup
        unlink($testFilePath);
    }

    public function test_validate_audio_file_size_handles_missing_file(): void
    {
        $result = $this->service->validateAudioFileSize('/nonexistent/file.mp3');

        $this->assertFalse($result['valid']);
        $this->assertEquals(0, $result['file_size']);
    }

    public function test_validate_audio_file_size_uses_correct_config(): void
    {
        // Set specific config values
        $maxSize = 15 * 1024 * 1024; // 15MB
        Config::set('livestream-processing.audio_extraction.transcription_optimized.max_file_size', $maxSize);

        // Create a test file just under the limit
        $testFilePath = storage_path('app/limit_test.mp3');
        file_put_contents($testFilePath, str_repeat('a', $maxSize - 1000)); // Just under limit

        $result = $this->service->validateAudioFileSize($testFilePath);

        $this->assertTrue($result['valid']);
        $this->assertEquals($maxSize, $result['max_size']);

        // Cleanup
        unlink($testFilePath);
    }

    public function test_config_values_are_loaded_correctly(): void
    {
        $config = config('livestream-processing.audio_extraction');

        $this->assertIsArray($config);
        $this->assertArrayHasKey('transcription_optimized', $config);
        $this->assertArrayHasKey('fallback_compression', $config);
        $this->assertArrayHasKey('validation', $config);

        $optimized = $config['transcription_optimized'];
        $this->assertEquals(48, $optimized['bitrate']);
        $this->assertEquals(16000, $optimized['sample_rate']);
        $this->assertEquals(1, $optimized['channels']);
        $this->assertEquals(25 * 1024 * 1024, $optimized['max_file_size']);

        $fallback = $config['fallback_compression'];
        $this->assertEquals(32, $fallback['bitrate']);
        $this->assertEquals(16000, $fallback['sample_rate']);
        $this->assertEquals(1, $fallback['channels']);
    }

    public function test_compression_settings_are_speech_optimized(): void
    {
        $config = config('livestream-processing.audio_extraction.transcription_optimized');

        // Verify settings are optimized for speech transcription
        $this->assertLessThanOrEqual(48, $config['bitrate']); // Low bitrate for size
        $this->assertEquals(16000, $config['sample_rate']); // 16kHz sufficient for speech
        $this->assertEquals(1, $config['channels']); // Mono for speech
        $this->assertEquals(25 * 1024 * 1024, $config['max_file_size']); // OpenAI Whisper limit
    }

    public function test_fallback_compression_is_more_aggressive(): void
    {
        $optimized = config('livestream-processing.audio_extraction.transcription_optimized');
        $fallback = config('livestream-processing.audio_extraction.fallback_compression');

        // Fallback should have lower bitrate for more aggressive compression
        $this->assertLessThan($optimized['bitrate'], $fallback['bitrate']);

        // Both should use same sample rate and channels for consistency
        $this->assertEquals($optimized['sample_rate'], $fallback['sample_rate']);
        $this->assertEquals($optimized['channels'], $fallback['channels']);
    }

    protected function tearDown(): void
    {
        // Clean up any test files that might remain
        $testFiles = [
            storage_path('app/small_test.mp3'),
            storage_path('app/large_test.mp3'),
            storage_path('app/limit_test.mp3'),
        ];

        foreach ($testFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }
}
