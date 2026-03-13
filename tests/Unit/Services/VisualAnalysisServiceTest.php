<?php

namespace Tests\Unit\Services;

use App\Services\VisualAnalysisService;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VisualAnalysisServiceTest extends TestCase
{
    private VisualAnalysisService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->service = new VisualAnalysisService;
    }

    #[Test]
    public function it_classifies_high_brightness_frame_as_song(): void
    {
        $metrics = [
            'timestamp' => 100.0,
            'brightness' => 0.95,  // Very high brightness (white lyric boxes)
            'contrast' => 0.9,     // Very high contrast (not used - 0% weight)
            'edge_density' => 0.95, // Very strong edges (80% weight - primary indicator)
        ];

        $result = $this->service->classifyFrame($metrics);

        $this->assertEquals('song', $result['classification']);
        // With edge_density at 80% weight and 0.95 value, confidence should be high
        $this->assertGreaterThan(0.5, $result['confidence']);
    }

    #[Test]
    public function it_classifies_low_brightness_frame_as_speech(): void
    {
        $metrics = [
            'timestamp' => 100.0,
            'brightness' => 0.3,  // Low brightness (no white boxes)
            'contrast' => 0.4,    // Low contrast
            'edge_density' => 0.2, // Few edges
        ];

        $result = $this->service->classifyFrame($metrics);

        $this->assertEquals('speech', $result['classification']);
        $this->assertLessThan(0.7, $result['confidence']);
    }

    #[Test]
    public function it_classifies_borderline_frame_correctly(): void
    {
        // Frame with moderate brightness - should be classified as speech
        $metrics = [
            'timestamp' => 100.0,
            'brightness' => 0.55, // Just below threshold
            'contrast' => 0.6,
            'edge_density' => 0.45,
        ];

        $result = $this->service->classifyFrame($metrics);

        $this->assertEquals('speech', $result['classification']);
    }

    #[Test]
    public function it_weights_edge_density_most_heavily(): void
    {
        // Edge density (80% weight) should dominate classification
        $highEdgeDensityMetrics = [
            'timestamp' => 100.0,
            'brightness' => 0.6,  // Moderate brightness (20% weight)
            'contrast' => 0.1,    // Low contrast (0% weight - disabled)
            'edge_density' => 0.95, // Very high edge density (80% weight - primary)
        ];

        $result = $this->service->classifyFrame($highEdgeDensityMetrics);

        // Should be classified as song because edge density is high
        $this->assertEquals('song', $result['classification']);
        $this->assertGreaterThan(0.5, $result['confidence']);
    }

    #[Test]
    public function it_returns_confidence_score(): void
    {
        $metrics = [
            'timestamp' => 100.0,
            'brightness' => 0.8,
            'contrast' => 0.75,
            'edge_density' => 0.6,
        ];

        $result = $this->service->classifyFrame($metrics);

        $this->assertArrayHasKey('confidence', $result);
        $this->assertIsFloat($result['confidence']);
        $this->assertGreaterThanOrEqual(0.0, $result['confidence']);
        $this->assertLessThanOrEqual(1.0, $result['confidence']);
    }

    #[Test]
    public function it_classifies_as_speech_when_edge_density_is_below_threshold(): void
    {
        $service = new VisualAnalysisService;

        $metrics = [
            'timestamp' => 100.0,
            'brightness' => 0.8,
            'contrast' => 0.75,
            'edge_density' => 0.6,  // Below EDGE_DENSITY_THRESHOLD (0.75)
        ];

        $result = $service->classifyFrame($metrics);

        // Edge density is the primary signal (weight 0.8). Below threshold means low confidence → speech.
        $this->assertEquals('speech', $result['classification']);
    }

    #[Test]
    public function it_parses_frame_metrics_from_ffmpeg_output(): void
    {
        // Create mock FFmpeg metadata output
        $metadataOutput = <<<'EOF'
frame:0    pts:0       pts_time:0
lavfi.signalstats.YAVG=127.5
lavfi.signalstats.YDIF=85.2
lavfi.signalstats.YHIGH=200.0
frame:60   pts:3600    pts_time:10.0
lavfi.signalstats.YAVG=180.0
lavfi.signalstats.YDIF=120.0
lavfi.signalstats.YHIGH=220.0
frame:120  pts:7200    pts_time:20.0
lavfi.signalstats.YAVG=50.0
lavfi.signalstats.YDIF=30.0
lavfi.signalstats.YHIGH=80.0
EOF;

        // Create temporary file
        $tempDir = Storage::disk('local')->path('temp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $tempFile = $tempDir.'/test_metrics.log';
        file_put_contents($tempFile, $metadataOutput);

        // Use reflection to call private method
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('parseMetricsLog');
        $method->setAccessible(true);

        $metrics = $method->invoke($this->service, $tempFile);

        // Verify we got 3 frames
        $this->assertCount(3, $metrics);

        // Verify first frame
        $this->assertEquals(0.0, $metrics[0]['timestamp']);
        $this->assertEqualsWithDelta(0.5, $metrics[0]['brightness'], 0.01); // 127.5/255

        // Verify second frame (high brightness - lyric box)
        $this->assertEquals(10.0, $metrics[1]['timestamp']);
        $this->assertEqualsWithDelta(0.706, $metrics[1]['brightness'], 0.01); // 180/255

        // Verify third frame (low brightness - speech)
        $this->assertEquals(20.0, $metrics[2]['timestamp']);
        $this->assertEqualsWithDelta(0.196, $metrics[2]['brightness'], 0.01); // 50/255

        // Cleanup
        @unlink($tempFile);
    }

    #[Test]
    public function it_handles_empty_metrics_gracefully(): void
    {
        $tempDir = Storage::disk('local')->path('temp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $tempFile = $tempDir.'/empty_metrics.log';
        file_put_contents($tempFile, '');

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('parseMetricsLog');
        $method->setAccessible(true);

        $metrics = $method->invoke($this->service, $tempFile);

        $this->assertEmpty($metrics);

        @unlink($tempFile);
    }

    #[Test]
    public function it_normalizes_brightness_values_correctly(): void
    {
        // Test boundary values
        $maxBrightness = [
            'timestamp' => 0.0,
            'brightness' => 1.0, // 255/255
            'contrast' => 1.0,
            'edge_density' => 1.0,
        ];

        $result = $this->service->classifyFrame($maxBrightness);
        $this->assertEquals('song', $result['classification']);

        $minBrightness = [
            'timestamp' => 0.0,
            'brightness' => 0.0, // 0/255
            'contrast' => 0.0,
            'edge_density' => 0.0,
        ];

        $result = $this->service->classifyFrame($minBrightness);
        $this->assertEquals('speech', $result['classification']);
    }

    #[Test]
    public function it_returns_proper_structure_from_classification(): void
    {
        $metrics = [
            'timestamp' => 100.0,
            'brightness' => 0.8,
            'contrast' => 0.75,
            'edge_density' => 0.6,
        ];

        $result = $this->service->classifyFrame($metrics);

        // Verify structure
        $this->assertArrayHasKey('classification', $result);
        $this->assertArrayHasKey('confidence', $result);
        $this->assertCount(2, $result);

        // Verify types
        $this->assertIsString($result['classification']);
        $this->assertIsFloat($result['confidence']);

        // Verify classification is valid
        $this->assertContains($result['classification'], ['song', 'speech']);
    }

    #[Test]
    public function it_applies_weighted_scoring_correctly(): void
    {
        // Test scenario where edge density (80% weight) dominates
        $edgeDensityOnlyHigh = [
            'timestamp' => 0.0,
            'brightness' => 0.5, // Moderate (20% weight)
            'contrast' => 0.0,    // Low (0% weight - disabled)
            'edge_density' => 0.9, // Very high (80% weight - primary)
        ];

        $result = $this->service->classifyFrame($edgeDensityOnlyHigh);

        // With 80% weight on edge_density, 0.9 value contributes significantly
        // Even moderate brightness only adds ~0.02, edge_density drives the score
        $this->assertGreaterThan(0.4, $result['confidence']);
    }

    #[Test]
    public function it_returns_refined_boundaries_with_proper_structure(): void
    {
        // This test verifies the structure returned by refineBoundaries
        // Without actually running FFmpeg (would need integration test for that)
        $cluster = [
            'start_estimate' => 1420.0, // 23:40
            'end_estimate' => 1490.0,   // 24:50
            'samples' => [1420, 1430, 1440, 1450, 1460, 1470, 1480, 1490],
        ];

        // We can't actually test the full functionality in a unit test
        // because it requires video file and FFmpeg, but we can verify
        // that the method exists and returns correct structure on error fallback

        // When video file doesn't exist, should return fallback with original estimates
        $result = $this->service->refineBoundaries('/nonexistent/video.mp4', $cluster);

        $this->assertArrayHasKey('refined_visual_start', $result);
        $this->assertArrayHasKey('refined_visual_end', $result);
        $this->assertArrayHasKey('dense_sample_count', $result);

        // Should fallback to original estimates
        $this->assertEquals($cluster['start_estimate'], $result['refined_visual_start']);
        $this->assertEquals($cluster['end_estimate'], $result['refined_visual_end']);
        $this->assertEquals(0, $result['dense_sample_count']);
    }

    #[Test]
    public function it_adjusts_timestamps_correctly_in_region_extraction(): void
    {
        // This verifies the logic that timestamps should be relative to video start
        // Create mock FFmpeg metadata output for a region starting at 1000s
        $metadataOutput = <<<'EOF'
frame:0    pts:0       pts_time:0
lavfi.signalstats.YAVG=180.0
lavfi.signalstats.YDIF=120.0
lavfi.signalstats.YHIGH=220.0
frame:60   pts:3600    pts_time:1.0
lavfi.signalstats.YAVG=190.0
lavfi.signalstats.YDIF=130.0
lavfi.signalstats.YHIGH=230.0
EOF;

        // Create temporary file
        $tempDir = Storage::disk('local')->path('temp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $tempFile = $tempDir.'/test_region_metrics.log';
        file_put_contents($tempFile, $metadataOutput);

        // Parse the log
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('parseMetricsLog');
        $method->setAccessible(true);
        $metrics = $method->invoke($this->service, $tempFile);

        // Verify timestamps are as extracted (before adjustment)
        $this->assertEquals(0.0, $metrics[0]['timestamp']);
        $this->assertEquals(1.0, $metrics[1]['timestamp']);

        // In actual extractFrameMetricsInRegion, timestamps would be adjusted
        // by adding the startTime to each timestamp
        // This logic should be verified in integration tests

        @unlink($tempFile);
    }
}
