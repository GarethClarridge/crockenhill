<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Media\Video\VisualAnalysisService;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExportVisualMetricsCommandTest extends TestCase
{
    #[Test]
    public function it_exports_visual_metrics_to_csv(): void
    {
        Storage::fake('local');

        $videoPath = Storage::disk('local')->path('temp/test-video.mp4');
        $videoDirectory = dirname($videoPath);
        if (! is_dir($videoDirectory)) {
            mkdir($videoDirectory, 0755, true);
        }
        file_put_contents($videoPath, 'fake video');

        $outputPath = 'temp/exports/test-metrics.csv';

        $this->mock(VisualAnalysisService::class, function (MockInterface $mock) use ($videoPath): void {
            $mock->shouldReceive('extractFrameMetrics')
                ->once()
                ->with($videoPath, 5)
                ->andReturn([
                    [
                        'timestamp' => 10.0,
                        'brightness' => 0.4,
                        'contrast' => 0.1,
                        'edge_density' => 0.7,
                        'ylow' => 0.2,
                        'percentile_span' => 0.5,
                    ],
                    [
                        'timestamp' => 20.0,
                        'brightness' => 0.6,
                        'contrast' => 0.05,
                        'edge_density' => 0.8,
                        'ylow' => 0.3,
                        'percentile_span' => 0.5,
                    ],
                ]);
        });

        $this->artisan('media:export-visual-metrics', [
            'video-path' => $videoPath,
            '--interval' => 5,
            '--output' => $outputPath,
        ])
            ->expectsOutputToContain('Exported 2 samples')
            ->assertExitCode(0);

        $csvPath = Storage::disk('local')->path($outputPath);

        $this->assertFileExists($csvPath);
        $contents = file($csvPath, FILE_IGNORE_NEW_LINES);

        $this->assertIsArray($contents);
        $this->assertSame('timestamp,brightness,contrast,edge_density,ylow,percentile_span', $contents[0]);
        $this->assertSame('10.000000,0.400000,0.100000,0.700000,0.200000,0.500000', $contents[1]);
        $this->assertSame('20.000000,0.600000,0.050000,0.800000,0.300000,0.500000', $contents[2]);
    }

    #[Test]
    public function it_fails_when_the_video_file_does_not_exist(): void
    {
        Storage::fake('local');

        $this->artisan('media:export-visual-metrics', [
            'video-path' => '/tmp/does-not-exist.mp4',
        ])
            ->expectsOutputToContain('Video file not found')
            ->assertExitCode(1);
    }
}
