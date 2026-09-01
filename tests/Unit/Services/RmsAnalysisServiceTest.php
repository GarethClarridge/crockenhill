<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\SegmentationException;
use App\Services\Media\Audio\RmsAnalysisService;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RmsAnalysisServiceTest extends TestCase
{
    private RmsAnalysisService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('media-processing.segmentation.rms_threshold', -45.0);
        Config::set('media-processing.segmentation.min_section_duration', 30.0);
        Config::set('media-processing.segmentation.adaptive_thresholds', [
            'enabled' => true,
            'min_sample_count' => 1000,
            'speech_percentile' => 30,
            'min_threshold' => -80.0,
            'max_threshold' => -20.0,
            'fallback_enabled' => true,
        ]);

        $this->service = new RmsAnalysisService;
    }

    // ---- Instantiation ----

    #[Test]
    public function it_can_be_instantiated(): void
    {
        $this->assertInstanceOf(RmsAnalysisService::class, $this->service);
    }

    // ---- getRmsThreshold / getMinSectionDuration ----

    #[Test]
    public function it_returns_configured_rms_threshold(): void
    {
        $this->assertEquals(-45.0, $this->service->getRmsThreshold());
    }

    #[Test]
    public function it_returns_configured_min_section_duration(): void
    {
        $this->assertEquals(30.0, $this->service->getMinSectionDuration());
    }

    // ---- extractRmsData ----

    #[Test]
    public function it_extracts_rms_data_from_log_content(): void
    {
        $logContent = implode("\n", [
            'pts_time:0.000000',
            'lavfi.astats.Overall.RMS_level=-35.5',
            'pts_time:1.000000',
            'lavfi.astats.Overall.RMS_level=-42.3',
        ]);

        $result = $this->service->extractRmsData($logContent);

        $this->assertCount(2, $result);
        $this->assertEquals(0.0, $result[0]['time']);
        $this->assertEquals(-35.5, $result[0]['rms']);
        $this->assertEquals(1.0, $result[1]['time']);
        $this->assertEquals(-42.3, $result[1]['rms']);
    }

    #[Test]
    public function it_handles_inf_rms_values(): void
    {
        $logContent = implode("\n", [
            'pts_time:0.000000',
            'lavfi.astats.Overall.RMS_level=-inf',
        ]);

        $result = $this->service->extractRmsData($logContent);

        $this->assertCount(1, $result);
        $this->assertEquals(-999.0, $result[0]['rms']);
    }

    #[Test]
    public function it_returns_empty_array_for_empty_log(): void
    {
        $result = $this->service->extractRmsData('');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    #[Test]
    public function it_ignores_non_rms_lines(): void
    {
        $logContent = implode("\n", [
            'Some random log line',
            'pts_time:0.000000',
            'Another unrelated line',
            'lavfi.astats.Overall.RMS_level=-30.0',
        ]);

        $result = $this->service->extractRmsData($logContent);

        $this->assertCount(1, $result);
        $this->assertEquals(-30.0, $result[0]['rms']);
    }

    // ---- calculateSegmentRms ----

    #[Test]
    public function it_calculates_segment_rms_for_time_range(): void
    {
        $rmsData = [
            ['time' => 0.0, 'rms' => -30.0],
            ['time' => 1.0, 'rms' => -40.0],
            ['time' => 2.0, 'rms' => -35.0],
            ['time' => 3.0, 'rms' => -50.0],
        ];

        $result = $this->service->calculateSegmentRms(0.0, 2.0, $rmsData);

        $this->assertArrayHasKey('avg', $result);
        $this->assertArrayHasKey('peak', $result);
        $this->assertEquals(-35.0, $result['avg']); // (-30 + -40 + -35) / 3
        $this->assertEquals(-30.0, $result['peak']); // max of -30, -40, -35
    }

    #[Test]
    public function it_returns_defaults_when_no_data_in_range(): void
    {
        $rmsData = [
            ['time' => 10.0, 'rms' => -30.0],
        ];

        $result = $this->service->calculateSegmentRms(0.0, 5.0, $rmsData);

        $this->assertEquals(-50.0, $result['avg']);
        $this->assertEquals(-40.0, $result['peak']);
    }

    #[Test]
    public function it_excludes_inf_values_from_calculation(): void
    {
        $rmsData = [
            ['time' => 0.0, 'rms' => -999.0],  // -inf
            ['time' => 1.0, 'rms' => -30.0],
            ['time' => 2.0, 'rms' => -40.0],
        ];

        $result = $this->service->calculateSegmentRms(0.0, 2.0, $rmsData);

        $this->assertEquals(-35.0, $result['avg']); // (-30 + -40) / 2
        $this->assertEquals(-30.0, $result['peak']);
    }

    #[Test]
    public function it_reports_honest_silence_when_every_sample_in_the_window_is_inf(): void
    {
        $rmsData = [
            ['time' => 0.0, 'rms' => -999.0],
            ['time' => 1.0, 'rms' => -999.0],
            ['time' => 2.0, 'rms' => -999.0],
        ];

        $result = $this->service->calculateSegmentRms(0.0, 2.0, $rmsData);

        // Not the fabricated "quiet but not silent" fallback (-50.0/-40.0):
        // every sample in range was digital silence, so the window is reported
        // as silence, not invented speech.
        $this->assertEquals(-999.0, $result['avg']);
        $this->assertEquals(-999.0, $result['peak']);
    }

    #[Test]
    public function it_still_returns_the_no_data_fallback_for_a_genuinely_empty_window(): void
    {
        // No samples fall within the requested range at all — a different
        // condition from every sample being silent — so the existing "quiet
        // but not silent" fallback still applies.
        $rmsData = [
            ['time' => 100.0, 'rms' => -999.0],
        ];

        $result = $this->service->calculateSegmentRms(0.0, 5.0, $rmsData);

        $this->assertEquals(-50.0, $result['avg']);
        $this->assertEquals(-40.0, $result['peak']);
    }

    // ---- isEntirelySilent ----

    #[Test]
    public function it_reports_entirely_silent_when_every_sample_is_inf(): void
    {
        $rmsData = [
            ['time' => 0.0, 'rms' => -999.0],
            ['time' => 1.0, 'rms' => -999.0],
        ];

        $this->assertTrue($this->service->isEntirelySilent($rmsData));
    }

    #[Test]
    public function it_reports_not_entirely_silent_when_any_sample_has_signal(): void
    {
        $rmsData = [
            ['time' => 0.0, 'rms' => -999.0],
            ['time' => 1.0, 'rms' => -55.0],
        ];

        $this->assertFalse($this->service->isEntirelySilent($rmsData));
    }

    #[Test]
    public function it_reports_not_entirely_silent_for_an_empty_dataset(): void
    {
        $this->assertFalse($this->service->isEntirelySilent([]));
    }

    // ---- getTotalDuration ----

    #[Test]
    public function it_gets_total_duration_from_pts_time_lines(): void
    {
        $logContent = "pts_time:0.0\npts_time:10.5\npts_time:20.0";
        $lines = explode("\n", trim($logContent));

        $result = $this->service->getTotalDuration($logContent, $lines);

        $this->assertEquals(20.0, $result);
    }

    #[Test]
    public function it_falls_back_to_line_count_when_no_pts_time(): void
    {
        $logContent = "line1\nline2\nline3";
        $lines = explode("\n", trim($logContent));

        $result = $this->service->getTotalDuration($logContent, $lines);

        // 3 lines / 43.0
        $this->assertEqualsWithDelta(3 / 43.0, $result, 0.001);
    }

    // ---- parseAudioSections ----

    #[Test]
    public function it_parses_audio_sections_above_threshold(): void
    {
        // Build log with silence, then speech (above threshold), then silence
        $lines = [];
        for ($t = 0; $t < 100; $t++) {
            $lines[] = "pts_time:{$t}.0";
            if ($t >= 10 && $t <= 60) {
                $lines[] = 'lavfi.astats.Overall.RMS_level=-30.0'; // above -45
            } else {
                $lines[] = 'lavfi.astats.Overall.RMS_level=-60.0'; // below -45
            }
        }
        $logContent = implode("\n", $lines);

        $sections = $this->service->parseAudioSections($logContent);

        $this->assertNotEmpty($sections);
        $this->assertEquals(10.0, $sections[0]['start']);
    }

    #[Test]
    public function it_filters_out_sections_shorter_than_min_duration(): void
    {
        // Build a short speech section (5 seconds, below the 30s minimum)
        $lines = [];
        for ($t = 0; $t < 50; $t++) {
            $lines[] = "pts_time:{$t}.0";
            if ($t >= 10 && $t <= 14) {
                $lines[] = 'lavfi.astats.Overall.RMS_level=-30.0';
            } else {
                $lines[] = 'lavfi.astats.Overall.RMS_level=-60.0';
            }
        }
        $logContent = implode("\n", $lines);

        $sections = $this->service->parseAudioSections($logContent);

        $this->assertEmpty($sections);
    }

    #[Test]
    public function it_accepts_custom_threshold(): void
    {
        $lines = [];
        for ($t = 0; $t < 100; $t++) {
            $lines[] = "pts_time:{$t}.0";
            if ($t >= 10 && $t <= 60) {
                $lines[] = 'lavfi.astats.Overall.RMS_level=-25.0';
            } else {
                $lines[] = 'lavfi.astats.Overall.RMS_level=-30.0';
            }
        }
        $logContent = implode("\n", $lines);

        // With threshold -20, nothing should be above it
        $sections = $this->service->parseAudioSections($logContent, -20.0);
        $this->assertEmpty($sections);

        // With threshold -28, the speech section qualifies
        $sections = $this->service->parseAudioSections($logContent, -28.0);
        $this->assertNotEmpty($sections);
    }

    #[Test]
    public function it_closes_section_at_end_of_content(): void
    {
        // Speech starts and continues to the end
        $lines = [];
        for ($t = 0; $t < 100; $t++) {
            $lines[] = "pts_time:{$t}.0";
            if ($t >= 10) {
                $lines[] = 'lavfi.astats.Overall.RMS_level=-30.0';
            } else {
                $lines[] = 'lavfi.astats.Overall.RMS_level=-60.0';
            }
        }
        $logContent = implode("\n", $lines);

        $sections = $this->service->parseAudioSections($logContent);

        $this->assertNotEmpty($sections);
        $this->assertEquals(10.0, $sections[0]['start']);
        // End should be the max pts_time (99.0)
        $this->assertEquals(99.0, $sections[0]['end']);
    }

    // ---- determineThreshold ----

    #[Test]
    public function it_returns_fixed_threshold_when_adaptive_disabled(): void
    {
        Config::set('media-processing.segmentation.adaptive_thresholds', [
            'enabled' => false,
        ]);
        $service = new RmsAnalysisService;

        $result = $service->determineThreshold('some log content');

        $this->assertEquals(-45.0, $result['threshold']);
        $this->assertEquals('fixed', $result['method']);
    }

    #[Test]
    public function it_returns_fallback_threshold_with_insufficient_samples(): void
    {
        Config::set('media-processing.segmentation.adaptive_thresholds', [
            'enabled' => true,
            'min_sample_count' => 1000,
            'fallback_enabled' => true,
        ]);
        $service = new RmsAnalysisService;

        // Only 3 samples, below 1000 minimum
        $logContent = implode("\n", [
            'lavfi.astats.Overall.RMS_level=-30.0',
            'lavfi.astats.Overall.RMS_level=-40.0',
            'lavfi.astats.Overall.RMS_level=-35.0',
        ]);

        $result = $service->determineThreshold($logContent);

        $this->assertEquals(-45.0, $result['threshold']);
        $this->assertEquals('fallback', $result['method']);
    }

    #[Test]
    public function it_throws_when_adaptive_fails_and_fallback_disabled(): void
    {
        Config::set('media-processing.segmentation.adaptive_thresholds', [
            'enabled' => true,
            'min_sample_count' => 1000,
            'fallback_enabled' => false,
        ]);
        $service = new RmsAnalysisService;

        $logContent = 'lavfi.astats.Overall.RMS_level=-30.0';

        $this->expectException(SegmentationException::class);

        $service->determineThreshold($logContent);
    }

    #[Test]
    public function it_returns_adaptive_threshold_with_sufficient_samples(): void
    {
        Config::set('media-processing.segmentation.adaptive_thresholds', [
            'enabled' => true,
            'min_sample_count' => 10,
            'speech_percentile' => 30,
            'min_threshold' => -80.0,
            'max_threshold' => -20.0,
            'fallback_enabled' => true,
        ]);
        $service = new RmsAnalysisService;

        // Generate 20 RMS values
        $lines = [];
        for ($i = 0; $i < 20; $i++) {
            $rms = -60.0 + ($i * 2); // -60 to -22
            $lines[] = "lavfi.astats.Overall.RMS_level={$rms}";
        }
        $logContent = implode("\n", $lines);

        $result = $service->determineThreshold($logContent);

        $this->assertEquals('adaptive', $result['method']);
        $this->assertArrayHasKey('rms_stats', $result);
        $this->assertGreaterThanOrEqual(-80.0, $result['threshold']);
        $this->assertLessThanOrEqual(-20.0, $result['threshold']);
    }

    // ---- calculateAdaptiveThreshold ----

    #[Test]
    public function it_returns_failure_for_insufficient_samples(): void
    {
        Config::set('media-processing.segmentation.adaptive_thresholds', [
            'enabled' => true,
            'min_sample_count' => 100,
        ]);
        $service = new RmsAnalysisService;

        $logContent = 'lavfi.astats.Overall.RMS_level=-30.0';

        $result = $service->calculateAdaptiveThreshold($logContent);

        $this->assertFalse($result['success']);
        $this->assertEquals('insufficient_samples', $result['error']);
    }

    #[Test]
    public function it_calculates_adaptive_threshold_with_statistics(): void
    {
        Config::set('media-processing.segmentation.adaptive_thresholds', [
            'enabled' => true,
            'min_sample_count' => 5,
            'speech_percentile' => 30,
            'min_threshold' => -80.0,
            'max_threshold' => -20.0,
        ]);
        $service = new RmsAnalysisService;

        $lines = [];
        for ($i = 0; $i < 10; $i++) {
            $rms = -50.0 + ($i * 3); // -50 to -23
            $lines[] = "lavfi.astats.Overall.RMS_level={$rms}";
        }
        $logContent = implode("\n", $lines);

        $result = $service->calculateAdaptiveThreshold($logContent);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('threshold', $result);
        $this->assertArrayHasKey('rms_stats', $result);
        $this->assertArrayHasKey('sample_count', $result['rms_stats']);
        $this->assertEquals(10, $result['rms_stats']['sample_count']);
    }

    #[Test]
    public function it_clamps_adaptive_threshold_to_bounds(): void
    {
        Config::set('media-processing.segmentation.adaptive_thresholds', [
            'enabled' => true,
            'min_sample_count' => 5,
            'speech_percentile' => 30,
            'min_threshold' => -40.0,
            'max_threshold' => -20.0,
        ]);
        $service = new RmsAnalysisService;

        // All very quiet values — percentile will be below min_threshold
        $lines = [];
        for ($i = 0; $i < 10; $i++) {
            $lines[] = 'lavfi.astats.Overall.RMS_level=-70.0';
        }
        $logContent = implode("\n", $lines);

        $result = $service->calculateAdaptiveThreshold($logContent);

        $this->assertTrue($result['success']);
        $this->assertEquals(-40.0, $result['threshold']); // Clamped to min
    }

    // ---- extractRmsForTimestamps ----

    #[Test]
    public function it_extracts_rms_for_specific_timestamps(): void
    {
        $rmsData = [
            ['time' => 0.0, 'rms' => -30.0],
            ['time' => 10.0, 'rms' => -40.0],
            ['time' => 20.0, 'rms' => -35.0],
            ['time' => 30.0, 'rms' => -45.0],
        ];

        // tolerance is 5.0, so exact matches at 10.0 and 30.0
        $result = $this->service->extractRmsForTimestamps($rmsData, [10.0, 30.0]);

        $this->assertCount(2, $result);
        $this->assertEquals(-40.0, $result[0]);
        $this->assertEquals(-45.0, $result[1]);
    }

    #[Test]
    public function it_uses_tolerance_when_matching_timestamps(): void
    {
        $rmsData = [
            ['time' => 10.0, 'rms' => -30.0],
        ];

        // Target is 12.0, within 5.0 tolerance of 10.0
        $result = $this->service->extractRmsForTimestamps($rmsData, [12.0]);

        $this->assertCount(1, $result);
        $this->assertEquals(-30.0, $result[0]);
    }

    #[Test]
    public function it_skips_timestamps_with_no_nearby_data(): void
    {
        $rmsData = [
            ['time' => 0.0, 'rms' => -30.0],
        ];

        // Target is 100.0, far from any data
        $result = $this->service->extractRmsForTimestamps($rmsData, [100.0]);

        $this->assertEmpty($result);
    }

    #[Test]
    public function it_skips_inf_values_in_timestamp_extraction(): void
    {
        $rmsData = [
            ['time' => 5.0, 'rms' => -999.0],
        ];

        $result = $this->service->extractRmsForTimestamps($rmsData, [5.0]);

        $this->assertEmpty($result);
    }

    // ---- extractRmsForRegion ----

    #[Test]
    public function it_extracts_rms_values_for_region(): void
    {
        $rmsData = [
            ['time' => 0.0, 'rms' => -30.0],
            ['time' => 5.0, 'rms' => -35.0],
            ['time' => 10.0, 'rms' => -40.0],
            ['time' => 15.0, 'rms' => -45.0],
            ['time' => 20.0, 'rms' => -50.0],
        ];

        $result = $this->service->extractRmsForRegion($rmsData, 5.0, 15.0);

        $this->assertCount(3, $result);
        $this->assertEquals([-35.0, -40.0, -45.0], $result);
    }

    #[Test]
    public function it_returns_empty_array_for_region_with_no_data(): void
    {
        $rmsData = [
            ['time' => 0.0, 'rms' => -30.0],
        ];

        $result = $this->service->extractRmsForRegion($rmsData, 10.0, 20.0);

        $this->assertEmpty($result);
    }

    #[Test]
    public function it_excludes_inf_values_from_region(): void
    {
        $rmsData = [
            ['time' => 5.0, 'rms' => -999.0],
            ['time' => 10.0, 'rms' => -35.0],
        ];

        $result = $this->service->extractRmsForRegion($rmsData, 0.0, 15.0);

        $this->assertCount(1, $result);
        $this->assertEquals(-35.0, $result[0]);
    }
}
