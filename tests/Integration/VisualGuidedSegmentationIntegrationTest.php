<?php

namespace Tests\Integration;

use App\Jobs\AnalyzeSegments;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Services\SongClusteringService;
use App\Services\VideoSegmentationService;
use App\Services\VisualAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VisualGuidedSegmentationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Config::set('media-processing.visual_analysis.enabled', true);
        Config::set('media-processing.visual_analysis.sample_interval_seconds', 10);
        Config::set('media-processing.visual_analysis.min_song_duration', 60);
        Config::set('media-processing.visual_analysis.max_gap_seconds', 30);
        Config::set('media-processing.segmentation.min_sermon_duration', 300);
    }

    #[Test]
    public function it_performs_end_to_end_visual_guided_segmentation(): void
    {
        // Create a realistic scenario: 1 hour livestream with 2 songs and sermon
        $processingLog = MediaProcessingLog::factory()->create([
            'source_file_path' => 'temp/test_livestream.mp4',
            'rms_log_path' => null,
            'status' => 'processing',
        ]);

        // Create simulated FFmpeg metadata output for visual analysis
        $this->createMockVideoMetadata($processingLog->source_file_path, [
            // Song 1: 5-7 minutes (white lyric boxes visible)
            ['time' => 300.0, 'brightness' => 0.8, 'contrast' => 0.75, 'edge_density' => 0.7],
            ['time' => 310.0, 'brightness' => 0.82, 'contrast' => 0.78, 'edge_density' => 0.72],
            ['time' => 320.0, 'brightness' => 0.79, 'contrast' => 0.73, 'edge_density' => 0.68],
            ['time' => 330.0, 'brightness' => 0.81, 'contrast' => 0.76, 'edge_density' => 0.71],
            ['time' => 340.0, 'brightness' => 0.83, 'contrast' => 0.77, 'edge_density' => 0.73],
            ['time' => 350.0, 'brightness' => 0.80, 'contrast' => 0.74, 'edge_density' => 0.69],
            ['time' => 360.0, 'brightness' => 0.82, 'contrast' => 0.75, 'edge_density' => 0.70],
            ['time' => 370.0, 'brightness' => 0.81, 'contrast' => 0.76, 'edge_density' => 0.71],
            ['time' => 380.0, 'brightness' => 0.79, 'contrast' => 0.74, 'edge_density' => 0.68],
            ['time' => 390.0, 'brightness' => 0.80, 'contrast' => 0.75, 'edge_density' => 0.69],
            ['time' => 400.0, 'brightness' => 0.82, 'contrast' => 0.77, 'edge_density' => 0.72],
            ['time' => 410.0, 'brightness' => 0.81, 'contrast' => 0.76, 'edge_density' => 0.70],
            ['time' => 420.0, 'brightness' => 0.79, 'contrast' => 0.73, 'edge_density' => 0.68],

            // Sermon: 7-50 minutes (preacher visible, no lyrics)
            ['time' => 430.0, 'brightness' => 0.4, 'contrast' => 0.5, 'edge_density' => 0.3],
            ['time' => 900.0, 'brightness' => 0.45, 'contrast' => 0.48, 'edge_density' => 0.35],
            ['time' => 1800.0, 'brightness' => 0.42, 'contrast' => 0.47, 'edge_density' => 0.32],
            ['time' => 2700.0, 'brightness' => 0.43, 'contrast' => 0.49, 'edge_density' => 0.33],

            // Song 2: 50-55 minutes (white lyric boxes visible again)
            ['time' => 3000.0, 'brightness' => 0.81, 'contrast' => 0.76, 'edge_density' => 0.71],
            ['time' => 3010.0, 'brightness' => 0.83, 'contrast' => 0.78, 'edge_density' => 0.73],
            ['time' => 3020.0, 'brightness' => 0.80, 'contrast' => 0.75, 'edge_density' => 0.69],
            ['time' => 3030.0, 'brightness' => 0.82, 'contrast' => 0.77, 'edge_density' => 0.72],
            ['time' => 3040.0, 'brightness' => 0.81, 'contrast' => 0.76, 'edge_density' => 0.70],
            ['time' => 3050.0, 'brightness' => 0.79, 'contrast' => 0.74, 'edge_density' => 0.68],
            ['time' => 3060.0, 'brightness' => 0.80, 'contrast' => 0.75, 'edge_density' => 0.69],
            ['time' => 3070.0, 'brightness' => 0.82, 'contrast' => 0.77, 'edge_density' => 0.71],
            ['time' => 3080.0, 'brightness' => 0.81, 'contrast' => 0.76, 'edge_density' => 0.70],
            ['time' => 3090.0, 'brightness' => 0.83, 'contrast' => 0.78, 'edge_density' => 0.73],
            ['time' => 3100.0, 'brightness' => 0.80, 'contrast' => 0.75, 'edge_density' => 0.69],
            ['time' => 3110.0, 'brightness' => 0.82, 'contrast' => 0.77, 'edge_density' => 0.72],
            ['time' => 3120.0, 'brightness' => 0.81, 'contrast' => 0.76, 'edge_density' => 0.71],
            ['time' => 3130.0, 'brightness' => 0.79, 'contrast' => 0.74, 'edge_density' => 0.68],
            ['time' => 3140.0, 'brightness' => 0.80, 'contrast' => 0.75, 'edge_density' => 0.69],
            ['time' => 3150.0, 'brightness' => 0.82, 'contrast' => 0.77, 'edge_density' => 0.72],
            ['time' => 3160.0, 'brightness' => 0.81, 'contrast' => 0.76, 'edge_density' => 0.70],
            ['time' => 3170.0, 'brightness' => 0.80, 'contrast' => 0.75, 'edge_density' => 0.69],
            ['time' => 3180.0, 'brightness' => 0.79, 'contrast' => 0.73, 'edge_density' => 0.68],
            ['time' => 3190.0, 'brightness' => 0.81, 'contrast' => 0.76, 'edge_density' => 0.71],
            ['time' => 3200.0, 'brightness' => 0.80, 'contrast' => 0.75, 'edge_density' => 0.70],
            ['time' => 3210.0, 'brightness' => 0.82, 'contrast' => 0.77, 'edge_density' => 0.72],
            ['time' => 3220.0, 'brightness' => 0.81, 'contrast' => 0.76, 'edge_density' => 0.71],
            ['time' => 3230.0, 'brightness' => 0.79, 'contrast' => 0.74, 'edge_density' => 0.68],
            ['time' => 3240.0, 'brightness' => 0.80, 'contrast' => 0.75, 'edge_density' => 0.69],
            ['time' => 3250.0, 'brightness' => 0.82, 'contrast' => 0.77, 'edge_density' => 0.72],
            ['time' => 3260.0, 'brightness' => 0.81, 'contrast' => 0.76, 'edge_density' => 0.70],
            ['time' => 3270.0, 'brightness' => 0.80, 'contrast' => 0.75, 'edge_density' => 0.69],
            ['time' => 3280.0, 'brightness' => 0.79, 'contrast' => 0.73, 'edge_density' => 0.68],
            ['time' => 3290.0, 'brightness' => 0.81, 'contrast' => 0.76, 'edge_density' => 0.71],
            ['time' => 3300.0, 'brightness' => 0.80, 'contrast' => 0.75, 'edge_density' => 0.70],

            // Closing: 55-60 minutes (announcements, no lyrics)
            ['time' => 3310.0, 'brightness' => 0.4, 'contrast' => 0.5, 'edge_density' => 0.3],
            ['time' => 3600.0, 'brightness' => 0.42, 'contrast' => 0.48, 'edge_density' => 0.32],
        ]);

        // Create RMS log with realistic audio levels
        $this->createMockRmsLog('temp/rms.log', [
            // Opening (quiet)
            ['time' => 0.0, 'rms' => -52.0],
            ['time' => 100.0, 'rms' => -50.0],
            ['time' => 200.0, 'rms' => -51.0],
            // Song 1 (loud)
            ['time' => 300.0, 'rms' => -33.0],
            ['time' => 350.0, 'rms' => -35.0],
            ['time' => 400.0, 'rms' => -34.0],
            ['time' => 420.0, 'rms' => -33.0],
            // Sermon (moderate)
            ['time' => 450.0, 'rms' => -48.0],
            ['time' => 900.0, 'rms' => -47.0],
            ['time' => 1800.0, 'rms' => -49.0],
            ['time' => 2700.0, 'rms' => -48.0],
            // Song 2 (loud)
            ['time' => 3000.0, 'rms' => -34.0],
            ['time' => 3100.0, 'rms' => -35.0],
            ['time' => 3200.0, 'rms' => -33.0],
            ['time' => 3300.0, 'rms' => -34.0],
            // Closing (quiet)
            ['time' => 3350.0, 'rms' => -50.0],
            ['time' => 3600.0, 'rms' => -51.0],
        ]);

        $processingLog->update(['rms_log_path' => 'temp/rms.log']);

        // Step 1: Visual Analysis (simulated by providing the metadata)
        $visualService = new VisualAnalysisService;
        $clusteringService = new SongClusteringService;

        // Manually classify frames based on our mock data
        $visualSamples = $this->classifyMockFrames($processingLog->source_file_path);

        // Cluster songs
        $songClusters = $clusteringService->clusterSongPeriods($visualSamples);

        // Store visual results
        $processingLog->update([
            'visual_samples' => json_encode($visualSamples),
            'song_clusters' => json_encode($songClusters),
            'visual_sample_count' => count($visualSamples),
        ]);

        // Verify clustering worked
        $this->assertCount(2, $songClusters, 'Should detect 2 song clusters');

        // Verify first song cluster
        $this->assertGreaterThanOrEqual(300.0, $songClusters[0]['start_estimate']);
        $this->assertLessThanOrEqual(450.0, $songClusters[0]['end_estimate']);

        // Verify second song cluster
        $this->assertGreaterThanOrEqual(3000.0, $songClusters[1]['start_estimate']);
        $this->assertLessThanOrEqual(3350.0, $songClusters[1]['end_estimate']);

        // Step 2: Segment Analysis with Visual Guidance
        $segmentationService = new VideoSegmentationService;
        $analyzeJob = new AnalyzeSegments($processingLog);
        $analyzeJob->handle($segmentationService);

        // Verify segments created
        $segments = LivestreamSegment::where('media_processing_log_id', $processingLog->id)
            ->orderBy('start_time')
            ->get();

        $this->assertGreaterThan(0, $segments->count(), 'Segments should be created');

        // Verify song segments exist
        $songSegments = $segments->where('classification', 'song');
        $this->assertCount(2, $songSegments, 'Should have 2 song segments');

        // Verify speech/sermon segment exists
        $speechSegments = $segments->where('classification', 'speech');
        $this->assertGreaterThan(0, $speechSegments->count(), 'Should have speech segments');

        // Find the sermon candidate (longest speech segment)
        $longestSpeech = $speechSegments->sortByDesc('duration')->first();
        $this->assertNotNull($longestSpeech);
        $this->assertTrue($longestSpeech->is_sermon_candidate, 'Longest speech should be sermon candidate');

        // Verify sermon is between the two songs
        $this->assertGreaterThan(420.0, $longestSpeech->start_time);
        $this->assertLessThan(3000.0, $longestSpeech->end_time);

        // Verify visual metadata stored
        foreach ($songSegments as $songSegment) {
            $this->assertNotNull($songSegment->calibration_method);
            $this->assertEquals('per_song_visual', $songSegment->calibration_method);
            $this->assertNotNull($songSegment->visual_confidence);
            $this->assertGreaterThan(0.7, $songSegment->visual_confidence);
        }

        // Verify processing log updated
        $processingLog->refresh();
        $this->assertNotNull($processingLog->sermon_start_time);
        $this->assertNotNull($processingLog->sermon_end_time);
        $this->assertEquals('segmenting', $processingLog->current_step);
    }

    #[Test]
    public function it_gracefully_degrades_to_rms_only_when_visual_fails(): void
    {
        $processingLog = MediaProcessingLog::factory()->create([
            'source_file_path' => 'temp/test_video.mp4',
            'status' => 'processing',
        ]);

        // Create RMS log but NO visual data (simulating visual analysis failure)
        $this->createMockRmsLog('temp/rms.log', [
            ['time' => 0.0, 'rms' => -50.0],
            ['time' => 300.0, 'rms' => -35.0],
            ['time' => 600.0, 'rms' => -48.0],
            ['time' => 3000.0, 'rms' => -47.0],
        ]);

        $processingLog->update([
            'rms_log_path' => 'temp/rms.log',
            'visual_samples' => null,
            'song_clusters' => null,
        ]);

        // Run segment analysis
        $segmentationService = new VideoSegmentationService;
        $analyzeJob = new AnalyzeSegments($processingLog);
        $analyzeJob->handle($segmentationService);

        // Verify it still works with RMS-only
        $segments = LivestreamSegment::where('media_processing_log_id', $processingLog->id)->get();
        $this->assertGreaterThan(0, $segments->count());

        // Verify no visual metadata
        foreach ($segments as $segment) {
            $this->assertNull($segment->visual_confidence);
            $this->assertNull($segment->calibration_method);
        }
    }

    private function createMockVideoMetadata(string $path, array $frames): void
    {
        // Store mock video file
        Storage::disk('local')->put($path, 'mock video content');

        // Store metadata for testing
        $metadataPath = str_replace('.mp4', '_metadata.json', $path);
        Storage::disk('local')->put($metadataPath, json_encode($frames));
    }

    private function createMockRmsLog(string $path, array $dataPoints): void
    {
        $lines = [];

        foreach ($dataPoints as $point) {
            $lines[] = "frame:X pts:X pts_time:{$point['time']}";
            $lines[] = "lavfi.astats.Overall.RMS_level={$point['rms']}";
        }

        Storage::disk('local')->put($path, implode("\n", $lines));
    }

    private function classifyMockFrames(string $videoPath): array
    {
        $metadataPath = str_replace('.mp4', '_metadata.json', $videoPath);
        $frames = json_decode(Storage::disk('local')->get($metadataPath), true);

        $visualService = new VisualAnalysisService;
        $samples = [];

        foreach ($frames as $frame) {
            $classification = $visualService->classifyFrame($frame);
            $samples[] = [
                'timestamp' => $frame['time'],
                'classification' => $classification['classification'],
                'confidence' => $classification['confidence'],
                'brightness' => $frame['brightness'],
                'contrast' => $frame['contrast'],
                'edge_density' => $frame['edge_density'],
            ];
        }

        return $samples;
    }
}
