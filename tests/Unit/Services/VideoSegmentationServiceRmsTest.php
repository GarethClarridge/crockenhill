<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\LivestreamSegment;
use App\Services\Media\Audio\RmsAnalysisService;
use App\Services\Media\Video\VideoSegmentationService;
use App\Services\Processing\StorageAdapterHelper;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VideoSegmentationServiceRmsTest extends TestCase
{
    private VideoSegmentationService $service;

    private RmsAnalysisService $rmsService;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        Config::set('media-processing.segmentation.rms_threshold', -45.0);
        Config::set('media-processing.segmentation.min_section_duration', 30.0);
        Config::set('media-processing.segmentation.min_sermon_duration', 300.0);
        Config::set('media-processing.storage.temp_disk', 'local');
        Config::set('media-processing.segmentation.adaptive_thresholds', [
            'enabled' => true,
            'min_sample_count' => 10,
            'speech_percentile' => 30,
            'min_threshold' => -80.0,
            'max_threshold' => -20.0,
            'fallback_enabled' => true,
        ]);

        $this->rmsService = new RmsAnalysisService;
        $this->service = new VideoSegmentationService($this->rmsService, new StorageAdapterHelper);
    }

    // ---- extractRmsData tests ----

    #[Test]
    public function it_extracts_rms_data_from_log_content(): void
    {
        $logContent = $this->buildRmsLog([
            ['time' => 0.0, 'rms' => -45.0],
            ['time' => 1.0, 'rms' => -38.0],
            ['time' => 2.0, 'rms' => -50.0],
        ]);

        $data = $this->rmsService->extractRmsData($logContent);

        $this->assertCount(3, $data);
        $this->assertEquals(0.0, $data[0]['time']);
        $this->assertEquals(-45.0, $data[0]['rms']);
        $this->assertEquals(-38.0, $data[1]['rms']);
    }

    #[Test]
    public function it_handles_negative_infinity_rms_values(): void
    {
        $logContent = "frame:X pts:X pts_time:0.0\nlavfi.astats.Overall.RMS_level=-inf\n"
            ."frame:X pts:X pts_time:1.0\nlavfi.astats.Overall.RMS_level=-42.5\n";

        $data = $this->rmsService->extractRmsData($logContent);

        $this->assertCount(2, $data);
        $this->assertEquals(-999.0, $data[0]['rms']);
        $this->assertEquals(-42.5, $data[1]['rms']);
    }

    // ---- calculateSegmentRms tests ----

    #[Test]
    public function it_calculates_segment_rms_average_and_peak(): void
    {
        $rmsData = [
            ['time' => 0.0, 'rms' => -50.0],
            ['time' => 10.0, 'rms' => -40.0],
            ['time' => 20.0, 'rms' => -30.0],
            ['time' => 30.0, 'rms' => -45.0],
            ['time' => 40.0, 'rms' => -999.0],
        ];

        $result = $this->rmsService->calculateSegmentRms(5.0, 35.0, $rmsData);

        $this->assertArrayHasKey('avg', $result);
        $this->assertArrayHasKey('peak', $result);
        $this->assertEquals(-30.0, $result['peak']);
        $this->assertEqualsWithDelta(-38.3, $result['avg'], 0.1);
    }

    #[Test]
    public function it_returns_fallback_rms_when_no_data_in_range(): void
    {
        $rmsData = [
            ['time' => 100.0, 'rms' => -40.0],
        ];

        $result = $this->rmsService->calculateSegmentRms(0.0, 10.0, $rmsData);

        $this->assertEquals(-50.0, $result['avg']);
        $this->assertEquals(-40.0, $result['peak']);
    }

    // ---- parseAudioSections tests ----

    #[Test]
    public function it_parses_loud_sections_from_rms_log(): void
    {
        $points = [];
        for ($t = 0; $t < 200; $t += 1) {
            $rms = ($t >= 60 && $t < 120) ? -35.0 : -55.0;
            $points[] = ['time' => (float) $t, 'rms' => $rms];
        }
        $logContent = $this->buildRmsLog($points);

        $sections = $this->rmsService->parseAudioSections($logContent, -45.0, 30.0);

        $this->assertCount(1, $sections);
        $this->assertEqualsWithDelta(60.0, $sections[0]['start'], 1.0);
        $this->assertEqualsWithDelta(120.0, $sections[0]['end'], 1.0);
    }

    #[Test]
    public function it_filters_out_short_loud_sections(): void
    {
        $points = [];
        for ($t = 0; $t < 100; $t += 1) {
            $rms = ($t >= 40 && $t < 50) ? -35.0 : -55.0;
            $points[] = ['time' => (float) $t, 'rms' => $rms];
        }
        $logContent = $this->buildRmsLog($points);

        $sections = $this->rmsService->parseAudioSections($logContent, -45.0, 30.0);

        $this->assertEmpty($sections);
    }

    #[Test]
    public function it_closes_open_section_at_end_of_file(): void
    {
        $points = [];
        for ($t = 0; $t < 100; $t += 1) {
            $rms = ($t >= 30) ? -35.0 : -55.0;
            $points[] = ['time' => (float) $t, 'rms' => $rms];
        }
        $logContent = $this->buildRmsLog($points);

        $sections = $this->rmsService->parseAudioSections($logContent, -45.0, 30.0);

        $this->assertCount(1, $sections);
        $this->assertEqualsWithDelta(30.0, $sections[0]['start'], 1.0);
        $this->assertGreaterThanOrEqual(90.0, $sections[0]['end']);
    }

    // ---- getTotalDuration tests ----

    #[Test]
    public function it_calculates_total_duration_from_pts_time(): void
    {
        $logContent = $this->buildRmsLog([
            ['time' => 0.0, 'rms' => -45.0],
            ['time' => 500.0, 'rms' => -45.0],
            ['time' => 1200.5, 'rms' => -45.0],
        ]);
        $lines = explode("\n", trim($logContent));

        $duration = $this->rmsService->getTotalDuration($logContent, $lines);

        $this->assertEquals(1200.5, $duration);
    }

    #[Test]
    public function it_falls_back_to_line_count_estimation_when_no_pts_time(): void
    {
        $lines = array_fill(0, 430, 'lavfi.astats.Overall.RMS_level=-45.0');
        $logContent = implode("\n", $lines);

        $duration = $this->rmsService->getTotalDuration($logContent, $lines);

        $this->assertEqualsWithDelta(10.0, $duration, 0.1);
    }

    // ---- combineLoudAndQuietSections tests ----

    #[Test]
    public function it_combines_loud_and_quiet_sections_with_correct_classifications(): void
    {
        Config::set('media-processing.segmentation.adaptive_thresholds.enabled', false);
        $this->refreshServices();

        $points = [];
        for ($t = 0; $t <= 200; $t += 1) {
            $rms = ($t >= 60 && $t < 120) ? -35.0 : -50.0;
            $points[] = ['time' => (float) $t, 'rms' => $rms];
        }
        $logContent = $this->buildRmsLog($points);
        Storage::disk('local')->put('temp/test_combine.log', $logContent);

        $result = $this->service->analyzeSegments('temp/test_combine.log');
        $segments = $result['segments'];

        $this->assertCount(3, $segments);

        $this->assertInstanceOf(LivestreamSegment::class, $segments[0]);
        $this->assertEquals('speech', $segments[0]->classification);
        $this->assertEquals(0.0, $segments[0]->startTime);
        $this->assertEquals(60.0, $segments[0]->endTime);

        $this->assertEquals('song', $segments[1]->classification);
        $this->assertEquals(60.0, $segments[1]->startTime);
        $this->assertEquals(120.0, $segments[1]->endTime);

        $this->assertEquals('speech', $segments[2]->classification);
        $this->assertEquals(120.0, $segments[2]->startTime);
        $this->assertEquals(200.0, $segments[2]->endTime);
    }

    #[Test]
    public function it_handles_no_loud_sections(): void
    {
        Config::set('media-processing.segmentation.adaptive_thresholds.enabled', false);
        $this->refreshServices();

        $points = [
            ['time' => 0.0, 'rms' => -50.0],
            ['time' => 100.0, 'rms' => -50.0],
        ];
        $logContent = $this->buildRmsLog($points);
        Storage::disk('local')->put('temp/test_no_loud.log', $logContent);

        $result = $this->service->analyzeSegments('temp/test_no_loud.log');
        $segments = $result['segments'];

        $this->assertCount(1, $segments);
        $this->assertEquals('speech', $segments[0]->classification);
        $this->assertEquals(0.0, $segments[0]->startTime);
        $this->assertEquals(100.0, $segments[0]->endTime);
    }

    #[Test]
    public function it_handles_consecutive_loud_sections(): void
    {
        Config::set('media-processing.segmentation.adaptive_thresholds.enabled', false);
        $this->refreshServices();

        $points = [];
        for ($t = 0; $t <= 300; $t += 1) {
            if (($t >= 30 && $t < 90) || ($t >= 150 && $t < 210)) {
                $rms = -30.0;
            } else {
                $rms = -50.0;
            }
            $points[] = ['time' => (float) $t, 'rms' => $rms];
        }
        $logContent = $this->buildRmsLog($points);
        Storage::disk('local')->put('temp/test_consecutive.log', $logContent);

        $result = $this->service->analyzeSegments('temp/test_consecutive.log');
        $segments = $result['segments'];

        $this->assertCount(5, $segments);
        $this->assertEquals('speech', $segments[0]->classification);
        $this->assertEquals('song', $segments[1]->classification);
        $this->assertEquals('speech', $segments[2]->classification);
        $this->assertEquals('song', $segments[3]->classification);
        $this->assertEquals('speech', $segments[4]->classification);
    }

    // ---- identifySermonCandidate tests ----

    #[Test]
    public function it_marks_longest_speech_segment_as_sermon_candidate(): void
    {
        Config::set('media-processing.segmentation.adaptive_thresholds.enabled', false);
        $this->refreshServices();

        $points = [];
        for ($t = 0; $t <= 660; $t += 1) {
            if (($t >= 60 && $t < 120) || ($t >= 600 && $t < 660)) {
                $rms = -30.0;
            } else {
                $rms = -50.0;
            }
            $points[] = ['time' => (float) $t, 'rms' => $rms];
        }
        // Resulting segments should be:
        // 0-60: speech (60s)
        // 60-120: song (60s)
        // 120-600: speech (480s) -> Sermon Candidate
        // 600-660: song (60s)
        $logContent = $this->buildRmsLog($points);
        Storage::disk('local')->put('temp/test_sermon_candidate.log', $logContent);

        $result = $this->service->analyzeSegments('temp/test_sermon_candidate.log');
        $segments = $result['segments'];

        $this->assertCount(4, $segments);
        $this->assertTrue($segments[2]->isSermonCandidate);
        $this->assertFalse($segments[0]->isSermonCandidate);
    }

    #[Test]
    public function it_does_not_mark_sermon_candidate_when_speech_too_short(): void
    {
        Config::set('media-processing.segmentation.adaptive_thresholds.enabled', false);
        Config::set('media-processing.segmentation.min_sermon_duration', 300.0);
        $this->refreshServices();

        $points = [];
        for ($t = 0; $t <= 240; $t += 1) {
            if ($t >= 60 && $t < 120) {
                $rms = -30.0;
            } else {
                $rms = -50.0;
            }
            $points[] = ['time' => (float) $t, 'rms' => $rms];
        }
        // Resulting segments:
        // 0-60: speech (60s)
        // 60-120: song (60s)
        // 120-240: speech (120s) -> Too short for sermon
        $logContent = $this->buildRmsLog($points);
        Storage::disk('local')->put('temp/test_too_short.log', $logContent);

        $result = $this->service->analyzeSegments('temp/test_too_short.log');
        $segments = $result['segments'];

        $this->assertNotEmpty($segments);
        foreach ($segments as $segment) {
            $this->assertFalse($segment->isSermonCandidate);
        }
    }

    #[Test]
    public function it_returns_segments_unchanged_when_no_speech(): void
    {
        Config::set('media-processing.segmentation.adaptive_thresholds.enabled', false);
        $this->refreshServices();

        // Entire file is above threshold -> one song segment
        $points = [];
        for ($t = 0; $t <= 240; $t += 1) {
            $points[] = ['time' => (float) $t, 'rms' => -30.0];
        }
        $logContent = $this->buildRmsLog($points);
        Storage::disk('local')->put('temp/test_no_speech.log', $logContent);

        $result = $this->service->analyzeSegments('temp/test_no_speech.log');
        $segments = $result['segments'];

        $this->assertCount(1, $segments);
        $this->assertEquals('song', $segments[0]->classification);
        $this->assertFalse($segments[0]->isSermonCandidate);
    }

    // ---- determineThreshold tests ----

    #[Test]
    public function it_returns_fixed_threshold_when_adaptive_disabled(): void
    {
        Config::set('media-processing.segmentation.adaptive_thresholds.enabled', false);
        $rmsService = new RmsAnalysisService;

        $logContent = $this->buildLargeRmsLog(2000);

        $result = $rmsService->determineThreshold($logContent);

        $this->assertEquals('fixed', $result['method']);
        $this->assertEquals(-45.0, $result['threshold']);
    }

    #[Test]
    public function it_calculates_adaptive_threshold_with_sufficient_samples(): void
    {
        $logContent = $this->buildLargeRmsLog(100);

        $result = $this->rmsService->determineThreshold($logContent);

        $this->assertEquals('adaptive', $result['method']);
        $this->assertIsFloat($result['threshold']);
        $this->assertGreaterThanOrEqual(-80.0, $result['threshold']);
        $this->assertLessThanOrEqual(-20.0, $result['threshold']);
    }

    #[Test]
    public function it_falls_back_to_fixed_threshold_with_insufficient_samples(): void
    {
        Config::set('media-processing.segmentation.adaptive_thresholds.min_sample_count', 1000);
        $rmsService = new RmsAnalysisService;

        $logContent = $this->buildRmsLog([
            ['time' => 0.0, 'rms' => -45.0],
            ['time' => 1.0, 'rms' => -42.0],
            ['time' => 2.0, 'rms' => -48.0],
            ['time' => 3.0, 'rms' => -40.0],
            ['time' => 4.0, 'rms' => -50.0],
        ]);

        $result = $rmsService->determineThreshold($logContent);

        $this->assertEquals('fallback', $result['method']);
        $this->assertEquals(-45.0, $result['threshold']);
    }

    // ---- calculateAdaptiveThreshold tests ----

    #[Test]
    public function it_calculates_adaptive_threshold_from_rms_distribution(): void
    {
        $logContent = $this->buildLargeRmsLog(100);

        $result = $this->rmsService->calculateAdaptiveThreshold($logContent);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('threshold', $result);
        $this->assertArrayHasKey('rms_stats', $result);
        $this->assertArrayHasKey('sample_count', $result['rms_stats']);
        $this->assertArrayHasKey('min', $result['rms_stats']);
        $this->assertArrayHasKey('max', $result['rms_stats']);
        $this->assertArrayHasKey('mean', $result['rms_stats']);
        $this->assertArrayHasKey('p25', $result['rms_stats']);
        $this->assertArrayHasKey('p50', $result['rms_stats']);
        $this->assertArrayHasKey('p75', $result['rms_stats']);
    }

    #[Test]
    public function it_clamps_adaptive_threshold_to_safety_bounds(): void
    {
        Config::set('media-processing.segmentation.adaptive_thresholds.min_threshold', -60.0);
        Config::set('media-processing.segmentation.adaptive_thresholds.max_threshold', -30.0);
        $rmsService = new RmsAnalysisService;

        $points = [];
        for ($i = 0; $i < 100; $i++) {
            $points[] = ['time' => (float) $i, 'rms' => -90.0];
        }
        $logContent = $this->buildRmsLog($points);

        $result = $rmsService->calculateAdaptiveThreshold($logContent);

        $this->assertTrue($result['success']);
        $this->assertGreaterThanOrEqual(-60.0, $result['threshold']);
    }

    #[Test]
    public function it_returns_failure_with_insufficient_samples(): void
    {
        $logContent = $this->buildRmsLog([
            ['time' => 0.0, 'rms' => -45.0],
        ]);

        $result = $this->rmsService->calculateAdaptiveThreshold($logContent);

        $this->assertFalse($result['success']);
        $this->assertEquals('insufficient_samples', $result['error']);
    }

    // ---- analyzeSegments integration test ----

    #[Test]
    public function it_performs_full_segment_analysis(): void
    {
        $points = [];
        for ($t = 0; $t < 1000; $t += 1) {
            if (($t >= 120 && $t < 240) || ($t >= 900 && $t < 960)) {
                $rms = -30.0 + rand(-5, 5);
            } else {
                $rms = -55.0 + rand(-5, 5);
            }
            $points[] = ['time' => (float) $t, 'rms' => (float) $rms];
        }
        $logContent = $this->buildRmsLog($points);

        $rmsLogPath = 'temp/test_rms_analysis.log';
        Storage::disk('local')->put($rmsLogPath, $logContent);

        $result = $this->service->analyzeSegments($rmsLogPath);

        $this->assertArrayHasKey('segments', $result);
        $this->assertArrayHasKey('threshold_metadata', $result);
        $this->assertNotEmpty($result['segments']);

        foreach ($result['segments'] as $segment) {
            $this->assertInstanceOf(LivestreamSegment::class, $segment);
        }

        $classifications = array_map(fn ($s) => $s->classification, $result['segments']);
        $this->assertContains('speech', $classifications);
        $this->assertContains('song', $classifications);
    }

    // ---- Helper methods ----

    private function buildRmsLog(array $dataPoints): string
    {
        $lines = [];
        foreach ($dataPoints as $point) {
            $rmsValue = $point['rms'] <= -999 ? '-inf' : $point['rms'];
            $lines[] = "frame:X pts:X pts_time:{$point['time']}";
            $lines[] = "lavfi.astats.Overall.RMS_level={$rmsValue}";
        }

        return implode("\n", $lines);
    }

    private function buildLargeRmsLog(int $sampleCount): string
    {
        $points = [];
        for ($i = 0; $i < $sampleCount; $i++) {
            $points[] = [
                'time' => (float) $i,
                'rms' => -30.0 - (float) rand(0, 40),
            ];
        }

        return $this->buildRmsLog($points);
    }

    private function refreshServices(): void
    {
        $this->rmsService = new RmsAnalysisService;
        $this->service = new VideoSegmentationService($this->rmsService, new StorageAdapterHelper);
    }
}
