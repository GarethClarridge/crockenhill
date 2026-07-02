<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Media\Video\VisualAnalysisService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VisualAnalysisServiceTest extends TestCase
{
    private VisualAnalysisService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        config(['media-processing.ffmpeg.ffmpeg_path' => base_path('scripts/testing/ffmpeg-stub.sh')]);
        config(['media-processing.storage.temp_disk' => 'local']);

        $this->service = new VisualAnalysisService;
    }

    #[Test]
    public function it_classifies_high_brightness_old_style_frame_as_song(): void
    {
        $result = $this->service->classifyFrame($this->makeMetrics(
            brightness: 0.95,
            contrast: 0.9,
            edgeDensity: 0.95,
            ylow: 0.55,
        ));

        $this->assertSame('song', $result['classification']);
        $this->assertSame('old_style', $result['detection_mode']);
        $this->assertGreaterThan(0.5, $result['confidence']);
    }

    #[Test]
    public function it_classifies_low_signal_frame_as_speech(): void
    {
        $result = $this->service->classifyFrame($this->makeMetrics(
            brightness: 0.3,
            contrast: 0.4,
            edgeDensity: 0.2,
            ylow: 0.15,
        ));

        $this->assertSame('speech', $result['classification']);
        $this->assertSame('none', $result['detection_mode']);
        $this->assertLessThan(0.35, $result['confidence']);
    }

    #[Test]
    public function it_classifies_borderline_old_style_frame_above_threshold_without_regression(): void
    {
        $result = $this->service->classifyFrame($this->makeMetrics(
            brightness: 0.5,
            contrast: 0.6,
            edgeDensity: 0.86,
            ylow: 0.5,
        ));

        $this->assertSame('song', $result['classification']);
        $this->assertSame('old_style', $result['detection_mode']);
        $this->assertSame(0.36, $result['confidence']);
    }

    #[Test]
    public function it_still_weights_edge_density_most_heavily_for_old_style_detection(): void
    {
        $result = $this->service->classifyFrame($this->makeMetrics(
            brightness: 0.6,
            contrast: 0.1,
            edgeDensity: 0.95,
            ylow: 0.58,
        ));

        $this->assertSame('song', $result['classification']);
        $this->assertSame('old_style', $result['detection_mode']);
        $this->assertGreaterThan(0.5, $result['confidence']);
    }

    #[Test]
    public function it_returns_confidence_score_within_expected_bounds(): void
    {
        $result = $this->service->classifyFrame($this->makeMetrics(
            brightness: 0.8,
            contrast: 0.75,
            edgeDensity: 0.6,
            ylow: 0.45,
        ));

        $this->assertArrayHasKey('confidence', $result);
        $this->assertIsFloat($result['confidence']);
        $this->assertGreaterThanOrEqual(0.0, $result['confidence']);
        $this->assertLessThanOrEqual(1.0, $result['confidence']);
    }

    #[Test]
    public function it_classifies_as_speech_when_edge_density_is_below_old_style_threshold(): void
    {
        $result = $this->service->classifyFrame($this->makeMetrics(
            brightness: 0.8,
            contrast: 0.75,
            edgeDensity: 0.6,
            ylow: 0.48,
        ));

        $this->assertSame('speech', $result['classification']);
        $this->assertSame('none', $result['detection_mode']);
    }

    #[Test]
    public function it_parses_ylow_and_percentile_span_from_ffmpeg_output(): void
    {
        $metadataOutput = <<<'EOF'
frame:0    pts:0       pts_time:0
lavfi.signalstats.YAVG=127.5
lavfi.signalstats.YDIF=85.2
lavfi.signalstats.YHIGH=200.0
lavfi.signalstats.YLOW=80.0
frame:60   pts:3600    pts_time:10.0
lavfi.signalstats.YAVG=180.0
lavfi.signalstats.YDIF=120.0
lavfi.signalstats.YHIGH=220.0
lavfi.signalstats.YLOW=40.0
frame:120  pts:7200    pts_time:20.0
lavfi.signalstats.YAVG=50.0
lavfi.signalstats.YDIF=30.0
lavfi.signalstats.YHIGH=80.0
lavfi.signalstats.YLOW=20.0
EOF;

        $videoFile = 'temp/test_video_'.Str::uuid().'.mp4';
        $stubFile = $videoFile.'.stub';

        Storage::disk('local')->put($videoFile, 'fake video content');
        Storage::disk('local')->put($stubFile, $metadataOutput);

        try {
            $metrics = $this->service->extractFrameMetrics(Storage::disk('local')->path($videoFile), 10);

            $this->assertCount(3, $metrics);

            $this->assertEquals(0.0, $metrics[0]['timestamp']);
            $this->assertEqualsWithDelta(0.5, $metrics[0]['brightness'], 0.01);
            $this->assertEqualsWithDelta(0.314, $metrics[0]['ylow'], 0.01);
            $this->assertEqualsWithDelta(0.471, $metrics[0]['percentile_span'], 0.01);

            $this->assertEquals(10.0, $metrics[1]['timestamp']);
            $this->assertEqualsWithDelta(0.706, $metrics[1]['brightness'], 0.01);
            $this->assertEqualsWithDelta(0.157, $metrics[1]['ylow'], 0.01);
            $this->assertEqualsWithDelta(0.706, $metrics[1]['percentile_span'], 0.01);

            $this->assertEquals(20.0, $metrics[2]['timestamp']);
            $this->assertEqualsWithDelta(0.196, $metrics[2]['brightness'], 0.01);
            $this->assertEqualsWithDelta(0.078, $metrics[2]['ylow'], 0.01);
            $this->assertEqualsWithDelta(0.235, $metrics[2]['percentile_span'], 0.01);
        } finally {
            Storage::disk('local')->delete([$videoFile, $stubFile]);
        }
    }

    #[Test]
    public function it_handles_empty_metrics_gracefully(): void
    {
        $videoFile = 'temp/empty_video_'.Str::uuid().'.mp4';
        Storage::disk('local')->put($videoFile, 'fake video content');

        try {
            // We expect an exception when the log is empty as per extractFrameMetrics implementation
            $this->expectException(\Exception::class);
            $this->expectExceptionMessage('Frame metrics log is empty or does not exist');

            $this->service->extractFrameMetrics(Storage::disk('local')->path($videoFile), 10);
        } finally {
            Storage::disk('local')->delete($videoFile);
        }
    }

    #[Test]
    public function it_normalizes_brightness_values_correctly(): void
    {
        $maxBrightness = $this->makeMetrics(
            brightness: 1.0,
            contrast: 1.0,
            edgeDensity: 1.0,
            ylow: 0.6,
        );

        $result = $this->service->classifyFrame($maxBrightness);
        $this->assertSame('song', $result['classification']);
        $this->assertSame('old_style', $result['detection_mode']);

        $minBrightness = $this->makeMetrics(
            brightness: 0.0,
            contrast: 0.0,
            edgeDensity: 0.0,
            ylow: 0.0,
        );

        $result = $this->service->classifyFrame($minBrightness);
        $this->assertSame('speech', $result['classification']);
        $this->assertSame('none', $result['detection_mode']);
    }

    #[Test]
    public function it_returns_proper_structure_from_classification(): void
    {
        $result = $this->service->classifyFrame($this->makeMetrics(
            brightness: 0.8,
            contrast: 0.75,
            edgeDensity: 0.6,
            ylow: 0.45,
        ));

        $this->assertArrayHasKey('classification', $result);
        $this->assertArrayHasKey('confidence', $result);
        $this->assertArrayHasKey('detection_mode', $result);
        $this->assertCount(3, $result);

        $this->assertIsString($result['classification']);
        $this->assertIsFloat($result['confidence']);
        $this->assertIsString($result['detection_mode']);
        $this->assertContains($result['classification'], ['song', 'speech']);
        $this->assertContains($result['detection_mode'], ['old_style', 'new_style', 'none']);
    }

    #[Test]
    public function it_applies_weighted_old_style_scoring_correctly(): void
    {
        $result = $this->service->classifyFrame($this->makeMetrics(
            brightness: 0.5,
            contrast: 0.0,
            edgeDensity: 0.9,
            ylow: 0.5,
        ));

        $this->assertSame(0.488, $result['confidence']);
        $this->assertSame('old_style', $result['detection_mode']);
    }

    #[Test]
    public function it_classifies_new_style_frame_as_song(): void
    {
        $result = $this->service->classifyFrame($this->makeMetrics(
            brightness: 0.35,
            contrast: 0.03,
            edgeDensity: 0.7,
            ylow: 0.12,
        ));

        $this->assertSame('song', $result['classification']);
        $this->assertSame('new_style', $result['detection_mode']);
        $this->assertSame(0.354, $result['confidence']);
    }

    #[Test]
    public function it_classifies_low_profile_lyric_frame_as_song(): void
    {
        $result = $this->service->classifyFrame($this->makeMetrics(
            brightness: 0.321,
            contrast: 0.01,
            edgeDensity: 0.49,
            ylow: 0.169,
        ));

        $this->assertSame('song', $result['classification']);
        $this->assertSame('new_style', $result['detection_mode']);
        $this->assertGreaterThan(0.35, $result['confidence']);
    }

    #[Test]
    public function it_does_not_apply_new_style_rules_when_old_style_has_been_forced(): void
    {
        $result = $this->service->classifyFrame(
            $this->makeMetrics(
                brightness: 0.321,
                contrast: 0.01,
                edgeDensity: 0.49,
                ylow: 0.169,
            ),
            VisualAnalysisService::RULE_SET_OLD_STYLE
        );

        $this->assertSame('speech', $result['classification']);
        $this->assertSame('none', $result['detection_mode']);
    }

    #[Test]
    public function it_does_not_apply_old_style_rules_when_new_style_has_been_forced(): void
    {
        $result = $this->service->classifyFrame(
            $this->makeMetrics(
                brightness: 0.95,
                contrast: 0.9,
                edgeDensity: 0.95,
                ylow: 0.55,
            ),
            VisualAnalysisService::RULE_SET_NEW_STYLE
        );

        $this->assertSame('speech', $result['classification']);
        $this->assertSame('none', $result['detection_mode']);
    }

    #[Test]
    public function it_scales_new_style_confidence_with_percentile_span(): void
    {
        $result = $this->service->classifyFrame($this->makeMetrics(
            brightness: 0.4,
            contrast: 0.02,
            edgeDensity: 0.85,
            ylow: 0.1,
        ));

        $this->assertSame('song', $result['classification']);
        $this->assertSame('new_style', $result['detection_mode']);
        $this->assertSame(0.615, $result['confidence']);
    }

    #[Test]
    public function it_prevents_mode_b_false_positives_for_speech_frames(): void
    {
        $result = $this->service->classifyFrame($this->makeMetrics(
            brightness: 0.45,
            contrast: 0.08,
            edgeDensity: 0.58,
            ylow: 0.44,
        ));

        $this->assertSame('speech', $result['classification']);
        $this->assertSame('none', $result['detection_mode']);
    }

    #[Test]
    public function it_does_not_treat_uniformly_dark_frames_as_new_style_song_slides(): void
    {
        $result = $this->service->classifyFrame($this->makeMetrics(
            brightness: 0.08,
            contrast: 0.01,
            edgeDensity: 0.15,
            ylow: 0.05,
        ));

        $this->assertSame('speech', $result['classification']);
        $this->assertSame('none', $result['detection_mode']);
    }

    #[Test]
    public function it_does_not_treat_bright_speech_frames_as_song_when_yhigh_is_not_high_enough(): void
    {
        $result = $this->service->classifyFrame($this->makeMetrics(
            brightness: 0.75,
            contrast: 0.05,
            edgeDensity: 0.7,
            ylow: 0.6,
        ));

        $this->assertSame('speech', $result['classification']);
        $this->assertSame('none', $result['detection_mode']);
    }

    #[Test]
    public function it_does_not_treat_static_speaker_frames_as_low_profile_song_slides(): void
    {
        $result = $this->service->classifyFrame($this->makeMetrics(
            brightness: 0.316,
            contrast: 0.106,
            edgeDensity: 0.698,
            ylow: 0.18,
        ));

        $this->assertSame('speech', $result['classification']);
        $this->assertSame('none', $result['detection_mode']);
    }

    #[Test]
    public function it_returns_refined_boundaries_with_proper_structure(): void
    {
        $cluster = [
            'start_estimate' => 1420.0,
            'end_estimate' => 1490.0,
            'samples' => [1420, 1430, 1440, 1450, 1460, 1470, 1480, 1490],
        ];

        $result = $this->service->refineBoundaries('/nonexistent/video.mp4', $cluster);

        $this->assertArrayHasKey('refined_visual_start', $result);
        $this->assertArrayHasKey('refined_visual_end', $result);
        $this->assertArrayHasKey('dense_sample_count', $result);
        $this->assertEquals($cluster['start_estimate'], $result['refined_visual_start']);
        $this->assertEquals($cluster['end_estimate'], $result['refined_visual_end']);
        $this->assertEquals(0, $result['dense_sample_count']);
    }

    #[Test]
    public function it_anchors_dense_boundary_refinement_to_the_original_cluster(): void
    {
        $denseSamples = [
            $this->makeMetrics(0.35, 0.01, 0.7, 0.12, 0.0),
            $this->makeMetrics(0.35, 0.01, 0.7, 0.12, 10.0),
            $this->makeMetrics(0.35, 0.01, 0.7, 0.12, 20.0),
            $this->makeMetrics(0.35, 0.01, 0.7, 0.12, 100.0),
            $this->makeMetrics(0.35, 0.01, 0.7, 0.12, 110.0),
            $this->makeMetrics(0.35, 0.01, 0.7, 0.12, 120.0),
            $this->makeMetrics(0.35, 0.01, 0.7, 0.12, 130.0),
            $this->makeMetrics(0.35, 0.01, 0.7, 0.12, 140.0),
            $this->makeMetrics(0.35, 0.01, 0.7, 0.12, 170.0),
            $this->makeMetrics(0.35, 0.01, 0.7, 0.12, 180.0),
            $this->makeMetrics(0.35, 0.01, 0.7, 0.12, 190.0),
            $this->makeMetrics(0.35, 0.01, 0.7, 0.12, 220.0),
            $this->makeMetrics(0.35, 0.01, 0.7, 0.12, 230.0),
            $this->makeMetrics(0.35, 0.01, 0.7, 0.12, 240.0),
        ];

        $service = new class($denseSamples) extends VisualAnalysisService
        {
            /**
             * @param  array<int, array{
             *     timestamp: float,
             *     brightness: float,
             *     contrast: float,
             *     edge_density: float,
             *     ylow: float,
             *     percentile_span: float
             * }>  $denseSamples
             */
            public function __construct(private readonly array $denseSamples)
            {
                parent::__construct();
            }

            public function extractFrameMetricsInRegion(
                string $videoPath,
                float $startTime,
                float $endTime,
                int $sampleInterval
            ): array {
                return $this->denseSamples;
            }
        };

        $cluster = [
            'start_estimate' => 100.0,
            'end_estimate' => 240.0,
            'samples' => [100.0, 110.0, 120.0, 130.0, 140.0, 170.0, 180.0, 190.0, 220.0, 230.0, 240.0],
        ];

        $result = $service->refineBoundaries('/nonexistent/video.mp4', $cluster);

        $this->assertSame(100.0, $result['refined_visual_start']);
        $this->assertSame(240.0, $result['refined_visual_end']);
    }

    #[Test]
    public function it_adjusts_timestamps_correctly_in_region_extraction(): void
    {
        $metadataOutput = <<<'EOF'
frame:0    pts:0       pts_time:0
lavfi.signalstats.YAVG=180.0
lavfi.signalstats.YDIF=120.0
lavfi.signalstats.YHIGH=220.0
lavfi.signalstats.YLOW=40.0
frame:60   pts:3600    pts_time:1.0
lavfi.signalstats.YAVG=190.0
lavfi.signalstats.YDIF=130.0
lavfi.signalstats.YHIGH=230.0
lavfi.signalstats.YLOW=45.0
EOF;

        $videoFile = 'temp/region_video_'.Str::uuid().'.mp4';
        $stubFile = $videoFile.'.stub';

        Storage::disk('local')->put($videoFile, 'fake video content');
        Storage::disk('local')->put($stubFile, $metadataOutput);

        try {
            $startTime = 100.0;
            $endTime = 110.0;
            $metrics = $this->service->extractFrameMetricsInRegion(Storage::disk('local')->path($videoFile), $startTime, $endTime, 1);

            $this->assertCount(2, $metrics);

            // extractFrameMetricsInRegion should add startTime to the timestamps from the log
            $this->assertEquals(100.0, $metrics[0]['timestamp']);
            $this->assertEquals(101.0, $metrics[1]['timestamp']);

            $this->assertArrayHasKey('ylow', $metrics[0]);
            $this->assertArrayHasKey('percentile_span', $metrics[0]);
        } finally {
            Storage::disk('local')->delete([$videoFile, $stubFile]);
        }
    }

    /**
     * @return array{
     *     timestamp: float,
     *     brightness: float,
     *     contrast: float,
     *     edge_density: float,
     *     ylow: float,
     *     percentile_span: float
     * }
     */
    private function makeMetrics(
        float $brightness,
        float $contrast,
        float $edgeDensity,
        float $ylow,
        float $timestamp = 100.0,
    ): array {
        return [
            'timestamp' => $timestamp,
            'brightness' => $brightness,
            'contrast' => $contrast,
            'edge_density' => $edgeDensity,
            'ylow' => $ylow,
            'percentile_span' => max(0.0, $edgeDensity - $ylow),
        ];
    }
}
