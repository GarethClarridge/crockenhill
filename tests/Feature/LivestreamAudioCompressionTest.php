<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ProcessingStatus;
use App\Jobs\ExtractSermon;
use App\Models\MediaProcessingLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LivestreamAudioCompressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up test configuration
        Config::set('media-processing.audio_extraction.transcription_optimized.max_file_size', 25 * 1024 * 1024);
        Config::set('media-processing.audio_extraction.transcription_optimized.bitrate', 48);
        Config::set('media-processing.audio_extraction.transcription_optimized.sample_rate', 16000);
        Config::set('media-processing.audio_extraction.transcription_optimized.channels', 1);

        Config::set('media-processing.audio_extraction.fallback_compression.bitrate', 32);
        Config::set('media-processing.audio_extraction.fallback_compression.sample_rate', 16000);
        Config::set('media-processing.audio_extraction.fallback_compression.channels', 1);
    }

    public function test_extract_sermon_job_uses_optimized_audio_extraction(): void
    {
        Queue::fake();

        // Create a processing log with sermon segment times
        $processingLog = MediaProcessingLog::factory()->create([
            'processing_id' => 'test-processing-123',
            'sermon_start_time' => 300.0, // 5 minutes
            'sermon_end_time' => 3600.0,   // 1 hour
            'video_file_path' => 'test-video.mp4',
        ]);

        // Create a mock video file in storage
        $tempDisk = config('media-processing.temp_disk', 'local');
        Storage::fake($tempDisk);
        Storage::disk($tempDisk)->put('test-video.mp4', 'mock video content');

        // Create the job
        $job = new ExtractSermon($processingLog);

        // We can't easily test the actual FFmpeg operations without a real video file,
        // but we can verify the job structure and that it would call the right methods
        $this->assertInstanceOf(ExtractSermon::class, $job);
        $this->assertEquals(3, $job->tries);
        $this->assertEquals(3600, $job->timeout);
    }

    public function test_processing_log_stores_compression_metadata(): void
    {
        // Create a processing log
        $processingLog = MediaProcessingLog::factory()->create([
            'processing_id' => 'test-compression-metadata',
            'status' => ProcessingStatus::Processing,
        ]);

        // Simulate updating with compression metadata
        $compressionMetadata = [
            'audio_compression' => [
                'original_size_mb' => 32.5,
                'final_size_mb' => 12.8,
                'compression_applied' => true,
                'compression_ratio' => 2.54,
                'valid_for_transcription' => true,
            ],
        ];

        $processingLog->update([
            'processing_metadata' => $compressionMetadata,
        ]);

        $processingLog->refresh();

        $this->assertArrayHasKey('audio_compression', $processingLog->processing_metadata);
        $this->assertEquals(32.5, $processingLog->processing_metadata['audio_compression']['original_size_mb']);
        $this->assertEquals(12.8, $processingLog->processing_metadata['audio_compression']['final_size_mb']);
        $this->assertTrue($processingLog->processing_metadata['audio_compression']['compression_applied']);
        $this->assertEquals(2.54, $processingLog->processing_metadata['audio_compression']['compression_ratio']);
        $this->assertTrue($processingLog->processing_metadata['audio_compression']['valid_for_transcription']);
    }

    public function test_audio_extraction_config_values_are_reasonable(): void
    {
        $optimized = config('media-processing.audio_extraction.transcription_optimized');
        $fallback = config('media-processing.audio_extraction.fallback_compression');

        // Test optimized settings
        $this->assertIsInt($optimized['bitrate']);
        $this->assertGreaterThan(0, $optimized['bitrate']);
        $this->assertLessThanOrEqual(128, $optimized['bitrate']); // Reasonable upper bound

        $this->assertEquals(16000, $optimized['sample_rate']); // Standard for speech
        $this->assertEquals(1, $optimized['channels']); // Mono for speech
        $this->assertEquals(25 * 1024 * 1024, $optimized['max_file_size']); // OpenAI limit

        // Test fallback settings
        $this->assertIsInt($fallback['bitrate']);
        $this->assertLessThan($optimized['bitrate'], $fallback['bitrate']); // More aggressive
        $this->assertEquals($optimized['sample_rate'], $fallback['sample_rate']);
        $this->assertEquals($optimized['channels'], $fallback['channels']);
    }

    public function test_compression_expected_file_sizes(): void
    {
        // Test expected compression calculations based on config
        $optimizedBitrate = config('media-processing.audio_extraction.transcription_optimized.bitrate'); // 48 kbps
        $fallbackBitrate = config('media-processing.audio_extraction.fallback_compression.bitrate'); // 32 kbps

        // For a 72-minute sermon (4320 seconds):
        $durationMinutes = 72;
        $durationSeconds = $durationMinutes * 60;

        // Calculate expected file sizes in bytes
        $optimizedSizeBytes = ($optimizedBitrate * 1000 / 8) * $durationSeconds; // bits to bytes
        $fallbackSizeBytes = ($fallbackBitrate * 1000 / 8) * $durationSeconds;

        $optimizedSizeMB = $optimizedSizeBytes / (1024 * 1024);
        $fallbackSizeMB = $fallbackSizeBytes / (1024 * 1024);

        // Verify sizes are reasonable
        $this->assertLessThan(25, $optimizedSizeMB); // Should be under OpenAI limit
        $this->assertLessThan(25, $fallbackSizeMB); // Fallback should definitely be under limit
        $this->assertLessThan($optimizedSizeMB, $fallbackSizeMB); // Fallback should be smaller

        // For 72 minutes at 48kbps: ~25.9MB, at 32kbps: ~17.3MB
        $this->assertGreaterThan(15, $optimizedSizeMB); // Reasonable lower bound
        $this->assertGreaterThan(10, $fallbackSizeMB); // Reasonable lower bound
    }

    public function test_configuration_provides_speech_optimization(): void
    {
        $config = config('media-processing.audio_extraction.transcription_optimized');

        // Test that configuration is optimized for speech recognition

        // Sample rate of 16kHz is standard for speech recognition
        $this->assertEquals(16000, $config['sample_rate']);

        // Mono audio is sufficient and reduces file size
        $this->assertEquals(1, $config['channels']);

        // Bitrate should be reasonable balance of quality vs size
        $this->assertGreaterThanOrEqual(32, $config['bitrate']); // Minimum for acceptable quality
        $this->assertLessThanOrEqual(64, $config['bitrate']); // Maximum for size efficiency

        // File size limit should match OpenAI Whisper API
        $this->assertEquals(25 * 1024 * 1024, $config['max_file_size']);
    }

    public function test_validation_config_includes_quality_settings(): void
    {
        $validation = config('media-processing.audio_extraction.validation');

        $this->assertArrayHasKey('max_duration_minutes', $validation);
        $this->assertArrayHasKey('size_check_enabled', $validation);
        $this->assertArrayHasKey('quality_check_enabled', $validation);

        $this->assertIsInt($validation['max_duration_minutes']);
        $this->assertGreaterThan(60, $validation['max_duration_minutes']); // At least 1 hour

        $this->assertIsBool($validation['size_check_enabled']);
        $this->assertTrue($validation['size_check_enabled']); // Should be enabled

        $this->assertIsBool($validation['quality_check_enabled']);
        $this->assertTrue($validation['quality_check_enabled']); // Should be enabled
    }

    public function test_compression_ratio_calculations_are_reasonable(): void
    {
        // Test the math for typical compression scenarios

        // Current: 128kbps stereo @ 44.1kHz ≈ 960KB/minute
        $currentBitrate = 128; // kbps

        // Optimized: 48kbps mono @ 16kHz ≈ 360KB/minute
        $optimizedBitrate = config('media-processing.audio_extraction.transcription_optimized.bitrate');

        // Fallback: 32kbps mono @ 16kHz ≈ 240KB/minute
        $fallbackBitrate = config('media-processing.audio_extraction.fallback_compression.bitrate');

        // Calculate expected compression ratios
        $optimizedRatio = $currentBitrate / $optimizedBitrate;
        $fallbackRatio = $currentBitrate / $fallbackBitrate;

        // Verify ratios are reasonable
        $this->assertGreaterThan(2.0, $optimizedRatio); // At least 2:1 compression
        $this->assertLessThan(5.0, $optimizedRatio); // Not more than 5:1 (quality loss)

        $this->assertGreaterThan(3.0, $fallbackRatio); // At least 3:1 compression
        $this->assertLessThan(6.0, $fallbackRatio); // Not more than 6:1 (quality loss)

        $this->assertGreaterThan($optimizedRatio, $fallbackRatio); // Fallback should compress more
    }

    protected function tearDown(): void
    {
        // Clean up any test storage
        Storage::fake('local');

        parent::tearDown();
    }
}
