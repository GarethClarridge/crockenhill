<?php

namespace Tests\Unit\Services;

use App\Services\SongClusteringService;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class SongClusteringServiceTest extends TestCase
{
    private SongClusteringService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Don't set global defaults - let each test configure as needed
        $this->service = new SongClusteringService;
    }

    /** @test */
    public function it_clusters_consecutive_song_samples(): void
    {
        Config::set('media-processing.visual_analysis.min_song_duration', 10);

        $visualSamples = [
            ['timestamp' => 10.0, 'classification' => 'speech', 'confidence' => 0.8],
            ['timestamp' => 20.0, 'classification' => 'song', 'confidence' => 0.9],
            ['timestamp' => 30.0, 'classification' => 'song', 'confidence' => 0.85],
            ['timestamp' => 40.0, 'classification' => 'song', 'confidence' => 0.88],
            ['timestamp' => 50.0, 'classification' => 'speech', 'confidence' => 0.75],
        ];

        $service = new SongClusteringService;  // Create after config
        $clusters = $service->clusterSongPeriods($visualSamples);

        $this->assertCount(1, $clusters);
        $this->assertEquals(20.0, $clusters[0]['start_estimate']);
        $this->assertEquals(40.0, $clusters[0]['end_estimate']);
        $this->assertEquals(3, $clusters[0]['sample_count']);
    }

    /** @test */
    public function it_creates_separate_clusters_for_distinct_songs(): void
    {
        Config::set('media-processing.visual_analysis.min_song_duration', 10);
        Config::set('media-processing.visual_analysis.max_gap_seconds', 20);

        $visualSamples = [
            ['timestamp' => 10.0, 'classification' => 'song', 'confidence' => 0.9],
            ['timestamp' => 20.0, 'classification' => 'song', 'confidence' => 0.85],
            ['timestamp' => 30.0, 'classification' => 'speech', 'confidence' => 0.8],
            ['timestamp' => 40.0, 'classification' => 'speech', 'confidence' => 0.75],
            ['timestamp' => 50.0, 'classification' => 'song', 'confidence' => 0.88],
            ['timestamp' => 60.0, 'classification' => 'song', 'confidence' => 0.92],
        ];

        $service = new SongClusteringService;  // Create after config
        $clusters = $service->clusterSongPeriods($visualSamples);

        $this->assertCount(2, $clusters);

        // First cluster
        $this->assertEquals(10.0, $clusters[0]['start_estimate']);
        $this->assertEquals(20.0, $clusters[0]['end_estimate']);
        $this->assertEquals(2, $clusters[0]['sample_count']);

        // Second cluster
        $this->assertEquals(50.0, $clusters[1]['start_estimate']);
        $this->assertEquals(60.0, $clusters[1]['end_estimate']);
        $this->assertEquals(2, $clusters[1]['sample_count']);
    }

    /** @test */
    public function it_merges_clusters_with_small_gaps(): void
    {
        Config::set('media-processing.visual_analysis.max_gap_seconds', 30);
        Config::set('media-processing.visual_analysis.min_song_duration', 10);

        $visualSamples = [
            ['timestamp' => 10.0, 'classification' => 'song', 'confidence' => 0.9],
            ['timestamp' => 20.0, 'classification' => 'song', 'confidence' => 0.85],
            ['timestamp' => 30.0, 'classification' => 'speech', 'confidence' => 0.8], // 10 sec gap
            ['timestamp' => 40.0, 'classification' => 'song', 'confidence' => 0.88],
            ['timestamp' => 50.0, 'classification' => 'song', 'confidence' => 0.92],
        ];

        $service = new SongClusteringService;
        $clusters = $service->clusterSongPeriods($visualSamples);

        // Should be merged into one cluster (gap is only 10 seconds)
        $this->assertCount(1, $clusters);
        $this->assertEquals(10.0, $clusters[0]['start_estimate']);
        $this->assertEquals(50.0, $clusters[0]['end_estimate']);
    }

    /** @test */
    public function it_does_not_merge_clusters_with_large_gaps(): void
    {
        Config::set('media-processing.visual_analysis.max_gap_seconds', 20);
        Config::set('media-processing.visual_analysis.min_song_duration', 0);  // Allow single samples
        Config::set('media-processing.visual_analysis.smoothing_window', 1);  // Disable smoothing

        $visualSamples = [
            ['timestamp' => 10.0, 'classification' => 'song', 'confidence' => 0.9],
            ['timestamp' => 20.0, 'classification' => 'song', 'confidence' => 0.85],
            ['timestamp' => 30.0, 'classification' => 'speech', 'confidence' => 0.8],
            ['timestamp' => 40.0, 'classification' => 'speech', 'confidence' => 0.75],
            ['timestamp' => 50.0, 'classification' => 'speech', 'confidence' => 0.77],
            ['timestamp' => 60.0, 'classification' => 'song', 'confidence' => 0.88],
            ['timestamp' => 70.0, 'classification' => 'song', 'confidence' => 0.90], // Make second cluster have >0 duration
        ];

        $service = new SongClusteringService;
        $clusters = $service->clusterSongPeriods($visualSamples);

        // Should be two separate clusters (gap from 20 to 60 is 40 seconds > max_gap of 20)
        $this->assertCount(2, $clusters);
    }

    /** @test */
    public function it_filters_clusters_by_minimum_duration(): void
    {
        Config::set('media-processing.visual_analysis.min_song_duration', 60);
        Config::set('media-processing.visual_analysis.smoothing_window', 1);  // Disable smoothing

        $visualSamples = [
            // Short cluster (20 seconds with 10s intervals: 10-30) - should be filtered
            ['timestamp' => 10.0, 'classification' => 'song', 'confidence' => 0.9],
            ['timestamp' => 20.0, 'classification' => 'song', 'confidence' => 0.85],
            ['timestamp' => 30.0, 'classification' => 'song', 'confidence' => 0.88],
            ['timestamp' => 40.0, 'classification' => 'speech', 'confidence' => 0.8],
            // Long cluster (60 seconds with 20s intervals: 100-160) - should be kept
            ['timestamp' => 100.0, 'classification' => 'song', 'confidence' => 0.9],
            ['timestamp' => 120.0, 'classification' => 'song', 'confidence' => 0.85],
            ['timestamp' => 140.0, 'classification' => 'song', 'confidence' => 0.88],
            ['timestamp' => 160.0, 'classification' => 'song', 'confidence' => 0.92],
        ];

        $service = new SongClusteringService;
        $clusters = $service->clusterSongPeriods($visualSamples);

        // Only the long cluster should remain
        $this->assertCount(1, $clusters);
        $this->assertEquals(100.0, $clusters[0]['start_estimate']);
        $this->assertEquals(160.0, $clusters[0]['end_estimate']);
    }

    /** @test */
    public function it_smooths_classifications_to_reduce_flickering(): void
    {
        Config::set('media-processing.visual_analysis.smoothing_window', 3);
        Config::set('media-processing.visual_analysis.min_song_duration', 10);

        $visualSamples = [
            ['timestamp' => 10.0, 'classification' => 'song', 'confidence' => 0.9],
            ['timestamp' => 20.0, 'classification' => 'speech', 'confidence' => 0.6], // Flicker
            ['timestamp' => 30.0, 'classification' => 'song', 'confidence' => 0.85],
            ['timestamp' => 40.0, 'classification' => 'song', 'confidence' => 0.88],
        ];

        $service = new SongClusteringService;
        $clusters = $service->clusterSongPeriods($visualSamples);

        // With smoothing, the brief speech flicker should be smoothed to song
        // Result should be one continuous cluster starting at 20.0 (first sample becomes speech due to edge effect)
        $this->assertCount(1, $clusters);
        $this->assertEquals(20.0, $clusters[0]['start_estimate']);  // Edge effect: first sample classified as speech
    }

    /** @test */
    public function it_calculates_average_confidence_for_clusters(): void
    {
        Config::set('media-processing.visual_analysis.smoothing_window', 1);  // Disable smoothing
        Config::set('media-processing.visual_analysis.min_song_duration', 10);

        $visualSamples = [
            ['timestamp' => 10.0, 'classification' => 'song', 'confidence' => 0.8],
            ['timestamp' => 20.0, 'classification' => 'song', 'confidence' => 0.9],
            ['timestamp' => 30.0, 'classification' => 'song', 'confidence' => 0.7],
        ];

        $service = new SongClusteringService;  // Create after config
        $clusters = $service->clusterSongPeriods($visualSamples);

        $this->assertCount(1, $clusters);
        $this->assertArrayHasKey('confidence', $clusters[0]);

        // Average should be (0.8 + 0.9 + 0.7) / 3 = 0.8
        $this->assertEqualsWithDelta(0.8, $clusters[0]['confidence'], 0.01);
    }

    /** @test */
    public function it_includes_sample_timestamps_in_clusters(): void
    {
        Config::set('media-processing.visual_analysis.min_song_duration', 10);

        $visualSamples = [
            ['timestamp' => 10.0, 'classification' => 'song', 'confidence' => 0.9],
            ['timestamp' => 20.0, 'classification' => 'song', 'confidence' => 0.85],
            ['timestamp' => 30.0, 'classification' => 'song', 'confidence' => 0.88],
        ];

        $service = new SongClusteringService;  // Create after config
        $clusters = $service->clusterSongPeriods($visualSamples);

        $this->assertCount(1, $clusters);
        $this->assertArrayHasKey('samples', $clusters[0]);
        $this->assertIsArray($clusters[0]['samples']);
        $this->assertEquals([10.0, 20.0, 30.0], $clusters[0]['samples']);
    }

    /** @test */
    public function it_handles_empty_input_gracefully(): void
    {
        $clusters = $this->service->clusterSongPeriods([]);

        $this->assertEmpty($clusters);
    }

    /** @test */
    public function it_handles_all_speech_samples(): void
    {
        $visualSamples = [
            ['timestamp' => 10.0, 'classification' => 'speech', 'confidence' => 0.8],
            ['timestamp' => 20.0, 'classification' => 'speech', 'confidence' => 0.75],
            ['timestamp' => 30.0, 'classification' => 'speech', 'confidence' => 0.77],
        ];

        $clusters = $this->service->clusterSongPeriods($visualSamples);

        $this->assertEmpty($clusters);
    }

    /** @test */
    public function it_handles_single_song_sample(): void
    {
        Config::set('media-processing.visual_analysis.min_song_duration', 0);
        Config::set('media-processing.visual_analysis.smoothing_window', 1);  // Disable smoothing

        $visualSamples = [
            ['timestamp' => 10.0, 'classification' => 'speech', 'confidence' => 0.8],
            ['timestamp' => 20.0, 'classification' => 'song', 'confidence' => 0.9],
            ['timestamp' => 30.0, 'classification' => 'speech', 'confidence' => 0.75],
        ];

        $service = new SongClusteringService;
        $clusters = $service->clusterSongPeriods($visualSamples);

        $this->assertCount(1, $clusters);
        $this->assertEquals(20.0, $clusters[0]['start_estimate']);
        $this->assertEquals(20.0, $clusters[0]['end_estimate']);
        $this->assertEquals(1, $clusters[0]['sample_count']);
    }

    /** @test */
    public function it_handles_alternating_classifications_correctly(): void
    {
        Config::set('media-processing.visual_analysis.smoothing_window', 1); // Disable smoothing
        Config::set('media-processing.visual_analysis.min_song_duration', 0); // Allow single samples
        Config::set('media-processing.visual_analysis.max_gap_seconds', 5);  // Don't merge 10s gaps

        $visualSamples = [
            ['timestamp' => 10.0, 'classification' => 'song', 'confidence' => 0.9],
            ['timestamp' => 20.0, 'classification' => 'speech', 'confidence' => 0.8],
            ['timestamp' => 30.0, 'classification' => 'song', 'confidence' => 0.85],
            ['timestamp' => 40.0, 'classification' => 'speech', 'confidence' => 0.75],
            ['timestamp' => 50.0, 'classification' => 'song', 'confidence' => 0.88],
        ];

        $service = new SongClusteringService;
        $clusters = $service->clusterSongPeriods($visualSamples);

        // Without smoothing and with small max_gap, should create 3 separate clusters
        $this->assertCount(3, $clusters);
    }

    /** @test */
    public function it_merges_multiple_close_clusters(): void
    {
        Config::set('media-processing.visual_analysis.max_gap_seconds', 30);
        Config::set('media-processing.visual_analysis.smoothing_window', 1);
        Config::set('media-processing.visual_analysis.min_song_duration', 10);  // Allow short clusters

        $visualSamples = [
            ['timestamp' => 10.0, 'classification' => 'song', 'confidence' => 0.9],
            ['timestamp' => 20.0, 'classification' => 'speech', 'confidence' => 0.8], // 10s gap
            ['timestamp' => 30.0, 'classification' => 'song', 'confidence' => 0.85],
            ['timestamp' => 40.0, 'classification' => 'speech', 'confidence' => 0.75], // 10s gap
            ['timestamp' => 50.0, 'classification' => 'song', 'confidence' => 0.88],
        ];

        $service = new SongClusteringService;
        $clusters = $service->clusterSongPeriods($visualSamples);

        // All gaps are ≤30s, should merge into one cluster
        $this->assertCount(1, $clusters);
        $this->assertEquals(10.0, $clusters[0]['start_estimate']);
        $this->assertEquals(50.0, $clusters[0]['end_estimate']);
    }
}
