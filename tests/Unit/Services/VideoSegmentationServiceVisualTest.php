<?php

namespace Tests\Unit\Services;

use App\Data\LivestreamSegment;
use App\Services\VideoSegmentationService;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VideoSegmentationServiceVisualTest extends TestCase
{
    private VideoSegmentationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->service = new VideoSegmentationService;
    }

    #[Test]
    public function it_calibrates_per_song_threshold_from_visual_cluster(): void
    {
        // Create mock RMS log with varying levels
        $rmsLog = $this->createMockRmsLog([
            // Pre-song speech (quiet)
            ['time' => 10.0, 'rms' => -50.0],
            ['time' => 20.0, 'rms' => -48.0],
            ['time' => 30.0, 'rms' => -52.0],
            // Song period (loud)
            ['time' => 40.0, 'rms' => -35.0],
            ['time' => 50.0, 'rms' => -33.0],
            ['time' => 60.0, 'rms' => -37.0],
            // Post-song speech (quiet)
            ['time' => 70.0, 'rms' => -49.0],
            ['time' => 80.0, 'rms' => -51.0],
        ]);

        $songCluster = [
            'start_estimate' => 40.0,
            'end_estimate' => 60.0,
            'samples' => [40.0, 50.0, 60.0],
            'confidence' => 0.85,
        ];

        $result = $this->service->calibratePerSongThreshold($rmsLog, $songCluster);

        // Verify structure
        $this->assertArrayHasKey('threshold', $result);
        $this->assertArrayHasKey('song_avg_rms', $result);
        $this->assertArrayHasKey('speech_avg_rms', $result);

        // Song should be louder than speech
        $this->assertGreaterThan($result['speech_avg_rms'], $result['song_avg_rms']);

        // Threshold should be between song and speech
        $this->assertGreaterThan($result['speech_avg_rms'], $result['threshold']);
        $this->assertLessThan($result['song_avg_rms'], $result['threshold']);
    }

    #[Test]
    public function it_applies_safety_bounds_to_calibrated_threshold(): void
    {
        // Create extreme RMS values
        $rmsLog = $this->createMockRmsLog([
            ['time' => 10.0, 'rms' => -100.0], // Very quiet
            ['time' => 20.0, 'rms' => -95.0],
            ['time' => 40.0, 'rms' => -5.0],   // Very loud
            ['time' => 50.0, 'rms' => -3.0],
        ]);

        $songCluster = [
            'start_estimate' => 40.0,
            'end_estimate' => 50.0,
            'samples' => [40.0, 50.0],
            'confidence' => 0.85,
        ];

        $result = $this->service->calibratePerSongThreshold($rmsLog, $songCluster);

        // Threshold should be clamped to safety bounds (-80 to -20)
        $this->assertGreaterThanOrEqual(-80.0, $result['threshold']);
        $this->assertLessThanOrEqual(-20.0, $result['threshold']);
    }

    #[Test]
    public function it_detects_boundaries_for_visual_cluster(): void
    {
        // Create RMS log with clear song boundaries
        $rmsLog = $this->createMockRmsLog([
            ['time' => 0.0, 'rms' => -50.0],   // Speech
            ['time' => 10.0, 'rms' => -48.0],  // Speech
            ['time' => 20.0, 'rms' => -35.0],  // Song starts
            ['time' => 30.0, 'rms' => -33.0],  // Song
            ['time' => 40.0, 'rms' => -32.0],  // Song
            ['time' => 50.0, 'rms' => -34.0],  // Song
            ['time' => 60.0, 'rms' => -49.0],  // Song ends, speech starts
            ['time' => 70.0, 'rms' => -51.0],  // Speech
        ]);

        $cluster = [
            'start_estimate' => 30.0,
            'end_estimate' => 50.0,
            'samples' => [30.0, 40.0, 50.0],
            'confidence' => 0.85,
        ];

        $threshold = -40.0; // Midpoint between song and speech

        $segment = $this->service->detectBoundariesForCluster($rmsLog, $cluster, $threshold);

        // Verify segment structure
        $this->assertInstanceOf(LivestreamSegment::class, $segment);
        $this->assertEquals('song', $segment->classification);

        // Boundaries should extend to actual RMS transitions
        // Start should be around 20s (where RMS rises above threshold)
        $this->assertGreaterThanOrEqual(15.0, $segment->startTime);
        $this->assertLessThanOrEqual(30.0, $segment->startTime);

        // End should be around 60s (where RMS drops below threshold)
        $this->assertGreaterThanOrEqual(50.0, $segment->endTime);
        $this->assertLessThanOrEqual(65.0, $segment->endTime);
    }

    #[Test]
    public function it_uses_min_max_approach_for_boundaries(): void
    {
        $rmsLog = $this->createMockRmsLog([
            ['time' => 10.0, 'rms' => -50.0],
            ['time' => 30.0, 'rms' => -35.0], // RMS suggests start here
            ['time' => 40.0, 'rms' => -33.0],
            ['time' => 50.0, 'rms' => -34.0],
            ['time' => 70.0, 'rms' => -50.0], // RMS suggests end here
        ]);

        $cluster = [
            'start_estimate' => 20.0,  // Original coarse estimate
            'end_estimate' => 80.0,    // Original coarse estimate
            'refined_visual_start' => 25.0,  // Dense visual sampling found this
            'refined_visual_end' => 75.0,    // Dense visual sampling found this
            'samples' => [20.0, 40.0, 60.0, 80.0],
            'confidence' => 0.85,
        ];

        $threshold = -40.0;

        $segment = $this->service->detectBoundariesForCluster($rmsLog, $cluster, $threshold);

        // Min/max approach: take earlier start, later end
        // Visual start: 25.0, RMS start: ~30.0 → final should be ~25.0 (min)
        // Visual end: 75.0, RMS end: ~70.0 → final should be ~75.0 (max)
        $this->assertLessThanOrEqual(25.0, $segment->startTime);
        $this->assertGreaterThanOrEqual(75.0, $segment->endTime);
    }

    #[Test]
    public function it_includes_calibration_metadata_in_segment(): void
    {
        $rmsLog = $this->createMockRmsLog([
            ['time' => 10.0, 'rms' => -50.0],
            ['time' => 40.0, 'rms' => -35.0],
            ['time' => 50.0, 'rms' => -33.0],
            ['time' => 70.0, 'rms' => -50.0],
        ]);

        $cluster = [
            'start_estimate' => 40.0,
            'end_estimate' => 50.0,
            'refined_visual_start' => 38.0,
            'refined_visual_end' => 52.0,
            'samples' => [40.0, 50.0],
            'confidence' => 0.85,
        ];

        $threshold = -40.0;

        $segment = $this->service->detectBoundariesForCluster($rmsLog, $cluster, $threshold);

        // Verify metadata
        $this->assertIsArray($segment->metadata);
        $this->assertArrayHasKey('threshold_used', $segment->metadata);
        $this->assertArrayHasKey('visual_sample_count', $segment->metadata);
        $this->assertArrayHasKey('visual_confidence', $segment->metadata);
        $this->assertArrayHasKey('calibration_method', $segment->metadata);
        $this->assertArrayHasKey('boundary_method', $segment->metadata);
        $this->assertArrayHasKey('visual_start', $segment->metadata);
        $this->assertArrayHasKey('visual_end', $segment->metadata);

        $this->assertEquals($threshold, $segment->metadata['threshold_used']);
        $this->assertEquals(2, $segment->metadata['visual_sample_count']);
        $this->assertEquals(0.85, $segment->metadata['visual_confidence']);
        $this->assertEquals('per_song_visual', $segment->metadata['calibration_method']);
        $this->assertContains($segment->metadata['boundary_method'], ['visual_only', 'visual_rms_union']);
    }

    #[Test]
    public function it_handles_missing_rms_data_gracefully(): void
    {
        $rmsLog = $this->createMockRmsLog([
            ['time' => 0.0, 'rms' => -50.0],
            // Large gap in data
            ['time' => 100.0, 'rms' => -50.0],
        ]);

        $cluster = [
            'start_estimate' => 40.0,
            'end_estimate' => 60.0,
            'samples' => [40.0, 50.0, 60.0],
            'confidence' => 0.85,
        ];

        $threshold = -40.0;

        $segment = $this->service->detectBoundariesForCluster($rmsLog, $cluster, $threshold);

        // Should fall back to cluster estimates
        $this->assertEquals(40.0, $segment->startTime);
        $this->assertEquals(60.0, $segment->endTime);
    }

    #[Test]
    public function it_calculates_segment_rms_correctly(): void
    {
        $rmsLog = $this->createMockRmsLog([
            ['time' => 30.0, 'rms' => -35.0],
            ['time' => 40.0, 'rms' => -33.0],
            ['time' => 50.0, 'rms' => -37.0],
            ['time' => 60.0, 'rms' => -34.0],
        ]);

        $cluster = [
            'start_estimate' => 30.0,
            'end_estimate' => 60.0,
            'samples' => [30.0, 40.0, 50.0, 60.0],
            'confidence' => 0.85,
        ];

        $threshold = -40.0;

        $segment = $this->service->detectBoundariesForCluster($rmsLog, $cluster, $threshold);

        // Verify RMS values
        $this->assertIsFloat($segment->avgRms);
        $this->assertIsFloat($segment->peakRms);

        // Peak should be highest value
        $this->assertEquals(-33.0, $segment->peakRms);

        // Average should be around -34.75
        $this->assertEqualsWithDelta(-34.75, $segment->avgRms, 0.5);
    }

    /**
     * Create a mock RMS log file and return its path
     */
    private function createMockRmsLog(array $dataPoints): string
    {
        $lines = [];

        foreach ($dataPoints as $point) {
            $lines[] = "frame:X pts:X pts_time:{$point['time']}";
            $lines[] = "lavfi.astats.Overall.RMS_level={$point['rms']}";
        }

        $content = implode("\n", $lines);

        $tempDir = Storage::disk('local')->path('temp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $filename = 'rms_'.uniqid().'.log';
        $fullPath = $tempDir.'/'.$filename;
        file_put_contents($fullPath, $content);

        return 'temp/'.$filename;
    }

    protected function tearDown(): void
    {
        // Cleanup temp files
        $tempDir = Storage::disk('local')->path('temp');
        if (is_dir($tempDir)) {
            $files = glob($tempDir.'/rms_*.log');
            foreach ($files as $file) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }
}
