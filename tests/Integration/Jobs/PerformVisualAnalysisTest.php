<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Jobs\PerformVisualAnalysis;
use App\Models\MediaProcessingLog;
use App\Services\Media\Video\VisualAnalysisService;
use App\Services\Song\SongClusteringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PerformVisualAnalysisTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_performs_visual_analysis_successfully(): void
    {
        Storage::fake('local');
        Config::set('media-processing.visual_analysis.enabled', true);

        // Create processing log
        $processingLog = MediaProcessingLog::factory()->create([
            'source_file_path' => 'temp/test_video.mp4',
            'status' => 'processing',
        ]);

        // Create mock video file
        Storage::disk('local')->put('temp/test_video.mp4', 'fake video content');

        // Mock services
        $mockVisualService = Mockery::mock(VisualAnalysisService::class);
        $mockClusteringService = Mockery::mock(SongClusteringService::class);

        $visualSamples = [
            ['timestamp' => 10.0, 'classification' => 'song', 'confidence' => 0.9],
            ['timestamp' => 20.0, 'classification' => 'song', 'confidence' => 0.85],
        ];

        $songClusters = [
            [
                'start_estimate' => 10.0,
                'end_estimate' => 20.0,
                'sample_count' => 2,
                'samples' => [10.0, 20.0],
                'confidence' => 0.875,
            ],
        ];

        $mockVisualService->shouldReceive('analyzeVideo')
            ->once()
            ->andReturn($visualSamples);

        $mockClusteringService->shouldReceive('clusterSongPeriods')
            ->once()
            ->with($visualSamples)
            ->andReturn($songClusters);

        // Mock refineBoundaries call
        $mockVisualService->shouldReceive('refineBoundaries')
            ->once()
            ->andReturn([
                'refined_visual_start' => 8.0,
                'refined_visual_end' => 22.0,
                'dense_sample_count' => 15,
            ]);

        // Execute job
        $job = new PerformVisualAnalysis($processingLog);
        $job->handle($mockVisualService, $mockClusteringService);

        // Verify results stored
        $processingLog->refresh();

        $this->assertNotNull($processingLog->visual_samples);
        $this->assertNotNull($processingLog->song_clusters);
        $this->assertEquals(2, $processingLog->visual_sample_count);
        $this->assertNotNull($processingLog->visual_processing_time);

        // visual_samples stays as raw JSON, song_clusters is now a typed collection wrapper
        $this->assertCount(2, $processingLog->visual_samples);
        $this->assertCount(1, $processingLog->song_clusters);
    }

    #[Test]
    public function it_uses_old_style_rules_for_uploads_before_april_1_2026(): void
    {
        Storage::fake('local');
        Config::set('media-processing.visual_analysis.enabled', true);

        $processingLog = MediaProcessingLog::factory()->create([
            'source_file_path' => 'temp/test_video.mp4',
            'status' => 'processing',
            'extracted_date' => '2026-03-31',
        ]);

        Storage::disk('local')->put('temp/test_video.mp4', 'fake video content');

        $mockVisualService = Mockery::mock(VisualAnalysisService::class);
        $mockClusteringService = Mockery::mock(SongClusteringService::class);

        $visualSamples = [
            ['timestamp' => 10.0, 'classification' => 'song', 'confidence' => 0.9],
            ['timestamp' => 20.0, 'classification' => 'song', 'confidence' => 0.85],
        ];
        $songClusters = [[
            'start_estimate' => 10.0,
            'end_estimate' => 20.0,
            'sample_count' => 2,
            'samples' => [10.0, 20.0],
            'confidence' => 0.875,
        ]];

        $mockVisualService->shouldReceive('analyzeVideo')
            ->once()
            ->withArgs(function (string $videoPath, ?int $sampleInterval, $progressCallback, string $ruleSet): bool {
                return str_ends_with($videoPath, 'temp/test_video.mp4')
                    && $sampleInterval === null
                    && $progressCallback instanceof \Closure
                    && $ruleSet === VisualAnalysisService::RULE_SET_OLD_STYLE;
            })
            ->andReturn($visualSamples);

        $mockClusteringService->shouldReceive('clusterSongPeriods')
            ->once()
            ->with($visualSamples)
            ->andReturn($songClusters);

        $mockVisualService->shouldReceive('refineBoundaries')
            ->once()
            ->withArgs(function (string $videoPath, array $cluster, string $ruleSet): bool {
                return str_ends_with($videoPath, 'temp/test_video.mp4')
                    && $cluster['start_estimate'] === 10.0
                    && $ruleSet === VisualAnalysisService::RULE_SET_OLD_STYLE;
            })
            ->andReturn([
                'refined_visual_start' => 8.0,
                'refined_visual_end' => 22.0,
                'dense_sample_count' => 15,
            ]);

        $job = new PerformVisualAnalysis($processingLog);
        $job->handle($mockVisualService, $mockClusteringService);
    }

    #[Test]
    public function it_uses_new_style_rules_for_uploads_on_or_after_april_1_2026(): void
    {
        Storage::fake('local');
        Config::set('media-processing.visual_analysis.enabled', true);

        $processingLog = MediaProcessingLog::factory()->create([
            'source_file_path' => 'temp/test_video.mp4',
            'status' => 'processing',
            'extracted_date' => '2026-04-01',
        ]);

        Storage::disk('local')->put('temp/test_video.mp4', 'fake video content');

        $mockVisualService = Mockery::mock(VisualAnalysisService::class);
        $mockClusteringService = Mockery::mock(SongClusteringService::class);

        $visualSamples = [
            ['timestamp' => 10.0, 'classification' => 'song', 'confidence' => 0.9],
            ['timestamp' => 20.0, 'classification' => 'song', 'confidence' => 0.85],
        ];
        $songClusters = [[
            'start_estimate' => 10.0,
            'end_estimate' => 20.0,
            'sample_count' => 2,
            'samples' => [10.0, 20.0],
            'confidence' => 0.875,
        ]];

        $mockVisualService->shouldReceive('analyzeVideo')
            ->once()
            ->withArgs(function (string $videoPath, ?int $sampleInterval, $progressCallback, string $ruleSet): bool {
                return str_ends_with($videoPath, 'temp/test_video.mp4')
                    && $sampleInterval === null
                    && $progressCallback instanceof \Closure
                    && $ruleSet === VisualAnalysisService::RULE_SET_NEW_STYLE;
            })
            ->andReturn($visualSamples);

        $mockClusteringService->shouldReceive('clusterSongPeriods')
            ->once()
            ->with($visualSamples)
            ->andReturn($songClusters);

        $mockVisualService->shouldReceive('refineBoundaries')
            ->once()
            ->withArgs(function (string $videoPath, array $cluster, string $ruleSet): bool {
                return str_ends_with($videoPath, 'temp/test_video.mp4')
                    && $cluster['start_estimate'] === 10.0
                    && $ruleSet === VisualAnalysisService::RULE_SET_NEW_STYLE;
            })
            ->andReturn([
                'refined_visual_start' => 8.0,
                'refined_visual_end' => 22.0,
                'dense_sample_count' => 15,
            ]);

        $job = new PerformVisualAnalysis($processingLog);
        $job->handle($mockVisualService, $mockClusteringService);
    }

    #[Test]
    public function it_skips_when_processing_is_cancelled(): void
    {
        $processingLog = MediaProcessingLog::factory()->create(['status' => 'cancelled']);

        $mockVisualService = Mockery::mock(VisualAnalysisService::class);
        $mockClusteringService = Mockery::mock(SongClusteringService::class);

        $mockVisualService->shouldNotReceive('analyzeVideo');
        $mockClusteringService->shouldNotReceive('clusterSongPeriods');

        $job = new PerformVisualAnalysis($processingLog);
        $job->handle($mockVisualService, $mockClusteringService);
    }

    #[Test]
    public function it_skips_when_visual_analysis_is_disabled(): void
    {
        Config::set('media-processing.visual_analysis.enabled', false);

        $processingLog = MediaProcessingLog::factory()->create([
            'status' => 'processing',
        ]);

        $mockVisualService = Mockery::mock(VisualAnalysisService::class);
        $mockClusteringService = Mockery::mock(SongClusteringService::class);

        // Services should not be called
        $mockVisualService->shouldNotReceive('analyzeVideo');
        $mockClusteringService->shouldNotReceive('clusterSongPeriods');

        $job = new PerformVisualAnalysis($processingLog);
        $job->handle($mockVisualService, $mockClusteringService);

        // Verify no visual data stored
        $processingLog->refresh();
        $this->assertNull($processingLog->visual_samples);
    }

    #[Test]
    public function it_skips_when_visual_analysis_already_completed(): void
    {
        Config::set('media-processing.visual_analysis.enabled', true);

        $processingLog = MediaProcessingLog::factory()->create([
            'status' => 'processing',
            'visual_sample_count' => 42,
        ]);

        $mockVisualService = Mockery::mock(VisualAnalysisService::class);
        $mockClusteringService = Mockery::mock(SongClusteringService::class);

        // Services should not be called since analysis is already done
        $mockVisualService->shouldNotReceive('analyzeVideo');
        $mockClusteringService->shouldNotReceive('clusterSongPeriods');

        $job = new PerformVisualAnalysis($processingLog);
        $job->handle($mockVisualService, $mockClusteringService);

        // Verify sample count was not changed
        $processingLog->refresh();
        $this->assertEquals(42, $processingLog->visual_sample_count);
    }

    #[Test]
    public function it_handles_no_visual_samples_gracefully(): void
    {
        Storage::fake('local');
        Config::set('media-processing.visual_analysis.enabled', true);

        $processingLog = MediaProcessingLog::factory()->create([
            'source_file_path' => 'temp/test_video.mp4',
            'status' => 'processing',
        ]);

        Storage::disk('local')->put('temp/test_video.mp4', 'fake video content');

        $mockVisualService = Mockery::mock(VisualAnalysisService::class);
        $mockClusteringService = Mockery::mock(SongClusteringService::class);

        // Return empty samples
        $mockVisualService->shouldReceive('analyzeVideo')
            ->once()
            ->andReturn([]);

        // Clustering should not be called with empty samples
        $mockClusteringService->shouldNotReceive('clusterSongPeriods');

        $job = new PerformVisualAnalysis($processingLog);
        $job->handle($mockVisualService, $mockClusteringService);

        // Verify empty state stored
        $processingLog->refresh();
        $this->assertEquals(0, $processingLog->visual_sample_count);
        $this->assertNotNull($processingLog->visual_processing_time);
    }

    #[Test]
    public function it_handles_insufficient_clusters(): void
    {
        Storage::fake('local');
        Config::set('media-processing.visual_analysis.enabled', true);
        Config::set('media-processing.visual_analysis.require_min_clusters', 2);

        $processingLog = MediaProcessingLog::factory()->create([
            'source_file_path' => 'temp/test_video.mp4',
            'status' => 'processing',
        ]);

        Storage::disk('local')->put('temp/test_video.mp4', 'fake video content');

        $mockVisualService = Mockery::mock(VisualAnalysisService::class);
        $mockClusteringService = Mockery::mock(SongClusteringService::class);

        $visualSamples = [
            ['timestamp' => 10.0, 'classification' => 'song', 'confidence' => 0.9],
        ];

        $songClusters = [
            ['start_estimate' => 10.0, 'end_estimate' => 20.0, 'sample_count' => 1],
        ];

        $mockVisualService->shouldReceive('analyzeVideo')->andReturn($visualSamples);
        $mockClusteringService->shouldReceive('clusterSongPeriods')->andReturn($songClusters);

        $job = new PerformVisualAnalysis($processingLog);
        $job->handle($mockVisualService, $mockClusteringService);

        // Should store results but not fail
        $processingLog->refresh();
        $this->assertEquals(1, $processingLog->visual_sample_count);
    }

    #[Test]
    public function it_falls_back_gracefully_on_failure_when_enabled(): void
    {
        Storage::fake('local');
        Config::set('media-processing.visual_analysis.enabled', true);
        Config::set('media-processing.visual_analysis.fallback_to_rms_on_failure', true);

        $processingLog = MediaProcessingLog::factory()->create([
            'source_file_path' => 'temp/test_video.mp4',
            'status' => 'processing',
        ]);

        Storage::disk('local')->put('temp/test_video.mp4', 'fake video content');

        $mockVisualService = Mockery::mock(VisualAnalysisService::class);
        $mockClusteringService = Mockery::mock(SongClusteringService::class);

        // Simulate failure
        $mockVisualService->shouldReceive('analyzeVideo')
            ->andThrow(new \Exception('FFmpeg error'));

        $job = new PerformVisualAnalysis($processingLog);

        // Should not throw exception
        $job->handle($mockVisualService, $mockClusteringService);

        // Verify failure state stored
        $processingLog->refresh();
        $this->assertEquals(0, $processingLog->visual_sample_count);
        $this->assertNull($processingLog->visual_processing_time);
    }

    #[Test]
    public function it_throws_exception_on_failure_when_fallback_disabled(): void
    {
        Storage::fake('local');
        Config::set('media-processing.visual_analysis.enabled', true);
        Config::set('media-processing.visual_analysis.fallback_to_rms_on_failure', false);

        $processingLog = MediaProcessingLog::factory()->create([
            'source_file_path' => 'temp/test_video.mp4',
            'status' => 'processing',
        ]);

        Storage::disk('local')->put('temp/test_video.mp4', 'fake video content');

        $mockVisualService = Mockery::mock(VisualAnalysisService::class);
        $mockClusteringService = Mockery::mock(SongClusteringService::class);

        $mockVisualService->shouldReceive('analyzeVideo')
            ->andThrow(new \Exception('FFmpeg error'));

        $job = new PerformVisualAnalysis($processingLog);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('FFmpeg error');

        $job->handle($mockVisualService, $mockClusteringService);
    }

    #[Test]
    public function it_stores_processing_time(): void
    {
        Storage::fake('local');
        Config::set('media-processing.visual_analysis.enabled', true);

        $processingLog = MediaProcessingLog::factory()->create([
            'source_file_path' => 'temp/test_video.mp4',
            'status' => 'processing',
        ]);

        Storage::disk('local')->put('temp/test_video.mp4', 'fake video content');

        $mockVisualService = Mockery::mock(VisualAnalysisService::class);
        $mockClusteringService = Mockery::mock(SongClusteringService::class);

        $mockVisualService->shouldReceive('analyzeVideo')->andReturn([
            ['timestamp' => 10.0, 'classification' => 'song', 'confidence' => 0.9],
        ]);

        $mockClusteringService->shouldReceive('clusterSongPeriods')->andReturn([
            ['start_estimate' => 10.0, 'end_estimate' => 20.0, 'sample_count' => 1, 'samples' => [10.0], 'confidence' => 0.9],
        ]);

        $mockVisualService->shouldReceive('refineBoundaries')->andReturn([
            'refined_visual_start' => 8.0,
            'refined_visual_end' => 22.0,
            'dense_sample_count' => 15,
        ]);

        $job = new PerformVisualAnalysis($processingLog);
        $job->handle($mockVisualService, $mockClusteringService);

        $processingLog->refresh();

        $this->assertIsFloat($processingLog->visual_processing_time);
        $this->assertGreaterThan(0, $processingLog->visual_processing_time);
    }
}
