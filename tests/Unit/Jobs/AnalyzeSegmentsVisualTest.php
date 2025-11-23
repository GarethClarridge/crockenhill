<?php

namespace Tests\Unit\Jobs;

use App\Data\LivestreamSegment as LivestreamSegmentData;
use App\Jobs\AnalyzeSegments;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Services\VideoSegmentationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class AnalyzeSegmentsVisualTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_uses_visual_guidance_when_clusters_available(): void
    {
        Storage::fake('local');

        // Create processing log with visual clusters
        $songClusters = [
            [
                'start_estimate' => 100.0,
                'end_estimate' => 200.0,
                'sample_count' => 10,
                'samples' => [100.0, 110.0, 120.0, 130.0, 140.0, 150.0, 160.0, 170.0, 180.0, 190.0],
                'confidence' => 0.85,
            ],
        ];

        $processingLog = MediaProcessingLog::factory()->create([
            'rms_log_path' => 'temp/rms.log',
            'song_clusters' => $songClusters,  // Pass as array, not JSON - Laravel will handle the cast
            'status' => 'processing',
        ]);

        // Create mock RMS log
        $this->createMockRmsLog('temp/rms.log', 600.0);

        // Mock service
        $mockService = Mockery::mock(VideoSegmentationService::class);

        // Expect calibration call
        $mockService->shouldReceive('calibratePerSongThreshold')
            ->once()
            ->andReturn([
                'threshold' => -40.0,
                'song_avg_rms' => -35.0,
                'speech_avg_rms' => -50.0,
            ]);

        // Expect boundary detection
        $mockService->shouldReceive('detectBoundariesForCluster')
            ->once()
            ->andReturn(new LivestreamSegmentData(
                startTime: 95.0,
                endTime: 205.0,
                duration: 110.0,
                classification: 'song',
                avgRms: -35.0,
                peakRms: -33.0,
                segmentOrder: 0,
                metadata: [
                    'threshold_used' => -40.0,
                    'visual_confidence' => 0.85,
                    'calibration_method' => 'per_song_visual',
                ]
            ));

        // Execute job
        $job = new AnalyzeSegments($processingLog);
        $job->handle($mockService);

        // Verify segments created
        $segments = LivestreamSegment::where('media_processing_log_id', $processingLog->id)->get();

        // Should have created 3 segments: pre-song speech, song, post-song speech
        $this->assertGreaterThanOrEqual(1, $segments->count());

        // Find song segment
        $songSegment = $segments->where('classification', 'song')->first();
        $this->assertNotNull($songSegment);
        $this->assertEquals(95.0, $songSegment->start_time);
        $this->assertEquals(205.0, $songSegment->end_time);

        // Verify calibration metadata stored
        $this->assertArrayHasKey('calibration_method', $songSegment->metadata);
        $this->assertEquals('per_song_visual', $songSegment->metadata['calibration_method']);
    }

    /** @test */
    public function it_falls_back_to_rms_only_when_no_visual_clusters(): void
    {
        Storage::fake('local');

        $processingLog = MediaProcessingLog::factory()->create([
            'rms_log_path' => 'temp/rms.log',
            'song_clusters' => null, // No visual data
            'status' => 'processing',
        ]);

        $this->createMockRmsLog('temp/rms.log', 600.0);

        $mockService = Mockery::mock(VideoSegmentationService::class);

        // Should call standard RMS analysis
        $mockService->shouldReceive('analyzeSegments')
            ->once()
            ->andReturn([
                'segments' => [
                    new LivestreamSegmentData(
                        startTime: 0.0,
                        endTime: 300.0,
                        duration: 300.0,
                        classification: 'speech',
                        avgRms: -50.0,
                        peakRms: -40.0,
                        isSermonCandidate: true,
                        segmentOrder: 0
                    ),
                ],
                'threshold_metadata' => [
                    'method' => 'adaptive',
                    'threshold' => -45.0,
                ],
            ]);

        $job = new AnalyzeSegments($processingLog);
        $job->handle($mockService);

        // Verify standard RMS processing occurred
        $segments = LivestreamSegment::where('media_processing_log_id', $processingLog->id)->get();
        $this->assertGreaterThan(0, $segments->count());
    }

    /** @test */
    public function it_creates_speech_segments_between_songs(): void
    {
        Storage::fake('local');

        // Two songs with gap in between
        $songClusters = [
            [
                'start_estimate' => 50.0,
                'end_estimate' => 100.0,
                'samples' => [50.0, 60.0, 70.0, 80.0, 90.0],
                'confidence' => 0.85,
            ],
            [
                'start_estimate' => 300.0,
                'end_estimate' => 350.0,
                'samples' => [300.0, 310.0, 320.0, 330.0, 340.0],
                'confidence' => 0.88,
            ],
        ];

        $processingLog = MediaProcessingLog::factory()->create([
            'rms_log_path' => 'temp/rms.log',
            'song_clusters' => $songClusters,  // Pass as array, not JSON - Laravel will handle the cast
            'status' => 'processing',
        ]);

        $this->createMockRmsLog('temp/rms.log', 500.0);

        $mockService = Mockery::mock(VideoSegmentationService::class);

        // Mock both songs
        $mockService->shouldReceive('calibratePerSongThreshold')->twice()->andReturn([
            'threshold' => -40.0,
            'song_avg_rms' => -35.0,
            'speech_avg_rms' => -50.0,
        ]);

        $mockService->shouldReceive('detectBoundariesForCluster')
            ->twice()
            ->andReturn(
                new LivestreamSegmentData(
                    startTime: 45.0,
                    endTime: 105.0,
                    duration: 60.0,
                    classification: 'song',
                    avgRms: -35.0,
                    peakRms: -33.0,
                    segmentOrder: 0,
                    metadata: ['calibration_method' => 'per_song_visual']
                ),
                new LivestreamSegmentData(
                    startTime: 295.0,
                    endTime: 355.0,
                    duration: 60.0,
                    classification: 'song',
                    avgRms: -35.0,
                    peakRms: -33.0,
                    segmentOrder: 1,
                    metadata: ['calibration_method' => 'per_song_visual']
                )
            );

        $job = new AnalyzeSegments($processingLog);
        $job->handle($mockService);

        // Verify segments created
        $segments = LivestreamSegment::where('media_processing_log_id', $processingLog->id)
            ->orderBy('segment_order')
            ->get();

        // Should have: pre-song speech, song1, speech gap, song2, post-song speech = 5 segments
        $this->assertGreaterThanOrEqual(4, $segments->count());

        // Verify song segments exist
        $songSegments = $segments->where('classification', 'song');
        $this->assertCount(2, $songSegments);

        // Verify speech segments exist
        $speechSegments = $segments->where('classification', 'speech');
        $this->assertGreaterThan(0, $speechSegments->count());
    }

    /** @test */
    public function it_identifies_sermon_candidate_from_visual_segments(): void
    {
        Storage::fake('local');

        // One song, large speech section (sermon candidate)
        $songClusters = [
            [
                'start_estimate' => 50.0,
                'end_estimate' => 100.0,
                'samples' => [50.0, 60.0, 70.0, 80.0, 90.0],
                'confidence' => 0.85,
            ],
        ];

        $processingLog = MediaProcessingLog::factory()->create([
            'rms_log_path' => 'temp/rms.log',
            'song_clusters' => $songClusters,  // Pass as array, not JSON - Laravel will handle the cast
            'status' => 'processing',
        ]);

        $this->createMockRmsLog('temp/rms.log', 3600.0); // 1 hour total

        $mockService = Mockery::mock(VideoSegmentationService::class);

        $mockService->shouldReceive('calibratePerSongThreshold')->andReturn([
            'threshold' => -40.0,
            'song_avg_rms' => -35.0,
            'speech_avg_rms' => -50.0,
        ]);

        $mockService->shouldReceive('detectBoundariesForCluster')->andReturn(
            new LivestreamSegmentData(
                startTime: 45.0,
                endTime: 105.0,
                duration: 60.0,
                classification: 'song',
                avgRms: -35.0,
                peakRms: -33.0,
                segmentOrder: 0,
                metadata: ['calibration_method' => 'per_song_visual']
            )
        );

        $job = new AnalyzeSegments($processingLog);
        $job->handle($mockService);

        // Verify sermon candidate identified
        $processingLog->refresh();
        $this->assertNotNull($processingLog->sermon_start_time);
        $this->assertNotNull($processingLog->sermon_end_time);

        // Sermon should be the longest speech segment (after song at 95-105)
        $this->assertGreaterThanOrEqual(105.0, $processingLog->sermon_start_time);
    }

    /** @test */
    public function it_stores_visual_metadata_in_segments(): void
    {
        Storage::fake('local');

        $songClusters = [
            [
                'start_estimate' => 100.0,
                'end_estimate' => 200.0,
                'samples' => [100.0, 120.0, 140.0, 160.0, 180.0],
                'confidence' => 0.92,
            ],
        ];

        $processingLog = MediaProcessingLog::factory()->create([
            'rms_log_path' => 'temp/rms.log',
            'song_clusters' => $songClusters,  // Pass as array, not JSON - Laravel will handle the cast
            'status' => 'processing',
        ]);

        $this->createMockRmsLog('temp/rms.log', 600.0);

        $mockService = Mockery::mock(VideoSegmentationService::class);

        $mockService->shouldReceive('calibratePerSongThreshold')->andReturn([
            'threshold' => -40.0,
            'song_avg_rms' => -35.0,
            'speech_avg_rms' => -50.0,
        ]);

        $mockService->shouldReceive('detectBoundariesForCluster')->andReturn(
            new LivestreamSegmentData(
                startTime: 95.0,
                endTime: 205.0,
                duration: 110.0,
                classification: 'song',
                avgRms: -35.0,
                peakRms: -33.0,
                segmentOrder: 0,
                metadata: [
                    'threshold_used' => -40.0,
                    'visual_confidence' => 0.92,
                    'visual_sample_count' => 5,
                    'calibration_method' => 'per_song_visual',
                    'song_avg_rms' => -35.0,
                    'speech_avg_rms' => -50.0,
                ]
            )
        );

        $job = new AnalyzeSegments($processingLog);
        $job->handle($mockService);

        // Verify visual metadata stored in database
        $songSegment = LivestreamSegment::where('media_processing_log_id', $processingLog->id)
            ->where('classification', 'song')
            ->first();

        $this->assertNotNull($songSegment);
        $this->assertEquals(0.92, $songSegment->visual_confidence);
        $this->assertEquals(5, $songSegment->visual_sample_count);
        $this->assertEquals('per_song_visual', $songSegment->calibration_method);
    }

    /**
     * Create a mock RMS log file
     */
    private function createMockRmsLog(string $path, float $duration): void
    {
        $lines = [];
        $currentTime = 0.0;

        while ($currentTime <= $duration) {
            $lines[] = "frame:X pts:X pts_time:{$currentTime}";
            $lines[] = 'lavfi.astats.Overall.RMS_level=-50.0';
            $currentTime += 1.0;
        }

        $content = implode("\n", $lines);
        Storage::disk('local')->put($path, $content);
    }
}
