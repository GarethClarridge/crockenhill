<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Data\LivestreamSegment as LivestreamSegmentData;
use App\Jobs\AnalyzeSegments;
use App\Models\MediaProcessingLog;
use App\Services\Media\Video\VideoSegmentationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AnalyzeSegments produces the coarse RMS sermon baseline
 * (sermon_start_time/sermon_end_time) that SermonExtractionPlanResolver falls
 * back to whenever the LLM structure yields no auto-extractable sermon section.
 *
 * Without this baseline, SermonExtractionPlanResolver::baselinePlan() throws
 * before ExtractSermon's confidence guard can route ambiguous runs to manual
 * review — turning a designed manual-review outcome into a hard failure.
 */
class AnalyzeSegmentsBaselineTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_writes_the_coarse_sermon_baseline_from_the_longest_speech_segment(): void
    {
        Storage::fake('local');

        $processingLog = MediaProcessingLog::factory()->create([
            'rms_log_path' => 'temp/rms.log',
            'status' => 'processing',
            'sermon_start_time' => null,
            'sermon_end_time' => null,
        ]);

        $this->createMockRmsLog('temp/rms.log', 1800.0);

        $mockService = Mockery::mock(VideoSegmentationService::class);
        $mockService->shouldReceive('analyzeSegments')
            ->once()
            ->andReturn([
                'segments' => [
                    // A short opening speech block…
                    new LivestreamSegmentData(
                        startTime: 0.0,
                        endTime: 120.0,
                        duration: 120.0,
                        classification: 'speech',
                        avgRms: -50.0,
                        peakRms: -40.0,
                        segmentOrder: 0,
                    ),
                    // …a song…
                    new LivestreamSegmentData(
                        startTime: 200.0,
                        endTime: 260.0,
                        duration: 60.0,
                        classification: 'song',
                        avgRms: -30.0,
                        peakRms: -20.0,
                        segmentOrder: 1,
                    ),
                    // …and the dominant speech block, which is the sermon baseline.
                    new LivestreamSegmentData(
                        startTime: 300.0,
                        endTime: 1700.0,
                        duration: 1400.0,
                        classification: 'speech',
                        avgRms: -48.0,
                        peakRms: -38.0,
                        segmentOrder: 2,
                    ),
                ],
                'threshold_metadata' => ['method' => 'adaptive', 'threshold' => -45.0],
            ]);

        (new AnalyzeSegments($processingLog))->handle($mockService);

        $processingLog->refresh();

        $this->assertEqualsWithDelta(300.0, (float) $processingLog->sermon_start_time, 0.01);
        $this->assertEqualsWithDelta(1700.0, (float) $processingLog->sermon_end_time, 0.01);
    }

    #[Test]
    public function it_leaves_the_sermon_baseline_unset_when_no_speech_segment_is_detected(): void
    {
        Storage::fake('local');

        $processingLog = MediaProcessingLog::factory()->create([
            'rms_log_path' => 'temp/rms.log',
            'status' => 'processing',
            'sermon_start_time' => null,
            'sermon_end_time' => null,
        ]);

        $this->createMockRmsLog('temp/rms.log', 600.0);

        $mockService = Mockery::mock(VideoSegmentationService::class);
        $mockService->shouldReceive('analyzeSegments')
            ->once()
            ->andReturn([
                'segments' => [
                    new LivestreamSegmentData(
                        startTime: 0.0,
                        endTime: 600.0,
                        duration: 600.0,
                        classification: 'song',
                        avgRms: -30.0,
                        peakRms: -20.0,
                        segmentOrder: 0,
                    ),
                ],
                'threshold_metadata' => ['method' => 'adaptive', 'threshold' => -45.0],
            ]);

        (new AnalyzeSegments($processingLog))->handle($mockService);

        $processingLog->refresh();

        $this->assertNull($processingLog->sermon_start_time);
        $this->assertNull($processingLog->sermon_end_time);
    }

    private function createMockRmsLog(string $path, float $duration): void
    {
        $lines = [];
        $currentTime = 0.0;

        while ($currentTime <= $duration) {
            $lines[] = "frame:X pts:X pts_time:{$currentTime}";
            $lines[] = 'lavfi.astats.Overall.RMS_level=-50.0';
            $currentTime += 1.0;
        }

        Storage::disk('local')->put($path, implode("\n", $lines));
    }
}
