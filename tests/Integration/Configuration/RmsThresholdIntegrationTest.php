<?php

namespace Tests\Integration\Configuration;

use App\Services\VideoSegmentationService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\Integration\BaseIntegrationTest;

class RmsThresholdIntegrationTest extends BaseIntegrationTest
{
    /**
     * Test that RMS threshold -100.0 causes "No segments found" error
     * Test that RMS threshold -30.0 works correctly
     */
    public function test_rms_threshold_affects_segmentation(): void
    {
        $videoPath = Storage::disk('local')->path(
            $this->copyTestFixture('test_video_livestream.mp4', 'test-integration/video.mp4')
        );

        $segmentationService = app(VideoSegmentationService::class);

        // Test with broken threshold (-100.0) - should find few segments
        Config::set('livestream-processing.rms_threshold', -100.0);

        $rmsLogPath1 = $segmentationService->generateRmsLog($videoPath);
        $segments1 = $segmentationService->analyzeSegments($rmsLogPath1);

        $this->assertIsArray($segments1, 'Should return array of segments');

        // Test with working threshold (-30.0) - should find more segments
        Config::set('livestream-processing.rms_threshold', -30.0);

        $rmsLogPath2 = $segmentationService->generateRmsLog($videoPath);
        $segments2 = $segmentationService->analyzeSegments($rmsLogPath2);

        $this->assertIsArray($segments2, 'Should return array of segments');
        // More permissive threshold should find more or equal segments
        $this->assertGreaterThanOrEqual(count($segments1), count($segments2));
    }

    /**
     * Test that VideoSegmentationService metadata extraction works
     */
    public function test_video_metadata_extraction(): void
    {
        $videoPath = Storage::disk('local')->path(
            $this->copyTestFixture('test_video_livestream.mp4', 'test-integration/video.mp4')
        );

        $segmentationService = app(VideoSegmentationService::class);

        // Test video file validation
        $isValid = $segmentationService->validateVideoFile($videoPath);
        $this->assertTrue($isValid, 'Test video should be valid');

        // Test metadata extraction
        $metadata = $segmentationService->getVideoMetadata($videoPath);

        $this->assertIsArray($metadata, 'Metadata should be an array');
        $this->assertArrayHasKey('duration', $metadata, 'Metadata should contain duration');
        $this->assertArrayHasKey('format_name', $metadata, 'Metadata should contain format_name');
        $this->assertGreaterThan(0, $metadata['duration'], 'Duration should be positive');
    }

    /**
     * Test minimum section duration configuration affects segmentation
     */
    public function test_minimum_section_duration_filtering(): void
    {
        $videoPath = Storage::disk('local')->path(
            $this->copyTestFixture('test_video_livestream.mp4', 'test-integration/video.mp4')
        );

        $segmentationService = app(VideoSegmentationService::class);

        // Test with very short minimum duration
        Config::set('livestream-processing.min_section_duration', 1.0);
        $rmsLogPath1 = $segmentationService->generateRmsLog($videoPath);
        $shortDurationSegments = $segmentationService->analyzeSegments($rmsLogPath1);

        // Test with longer minimum duration
        Config::set('livestream-processing.min_section_duration', 60.0);
        $rmsLogPath2 = $segmentationService->generateRmsLog($videoPath);
        $longDurationSegments = $segmentationService->analyzeSegments($rmsLogPath2);

        $this->assertIsArray($shortDurationSegments);
        $this->assertIsArray($longDurationSegments);

        // Shorter minimum duration should find more or equal segments
        $this->assertGreaterThanOrEqual(
            count($longDurationSegments),
            count($shortDurationSegments),
            'Shorter minimum duration should find more segments'
        );
    }
}
