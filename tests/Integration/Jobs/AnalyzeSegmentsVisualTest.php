<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Data\LivestreamSegment as LivestreamSegmentData;
use App\Enums\LivestreamSegmentClassification;
use App\Jobs\AnalyzeSegments;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Services\Media\Video\VideoSegmentationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AnalyzeSegmentsVisualTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
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
        $this->mockBaselineRmsAnalysis($mockService, 600.0);

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

    #[Test]
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

    #[Test]
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
        $this->mockBaselineRmsAnalysis($mockService, 500.0);

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
                    metadata: [
                        'visual_confidence' => 0.85,
                        'calibration_method' => 'per_song_visual',
                    ]
                ),
                new LivestreamSegmentData(
                    startTime: 295.0,
                    endTime: 355.0,
                    duration: 60.0,
                    classification: 'song',
                    avgRms: -35.0,
                    peakRms: -33.0,
                    segmentOrder: 1,
                    metadata: [
                        'visual_confidence' => 0.88,
                        'calibration_method' => 'per_song_visual',
                    ]
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

    #[Test]
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
        $this->mockBaselineRmsAnalysis($mockService, 3600.0);

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
                metadata: [
                    'visual_confidence' => 0.85,
                    'calibration_method' => 'per_song_visual',
                ]
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

    #[Test]
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
        $this->mockBaselineRmsAnalysis($mockService, 600.0, [
            new LivestreamSegmentData(
                startTime: 0.0,
                endTime: 70.0,
                duration: 70.0,
                classification: 'song',
                avgRms: -40.0,
                peakRms: -35.0,
                segmentOrder: 0,
            ),
        ]);

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

    #[Test]
    public function it_falls_back_to_the_baseline_threshold_when_per_song_calibration_has_no_rms_data(): void
    {
        Storage::fake('local');

        $songClusters = [
            [
                'start_estimate' => 0.0,
                'end_estimate' => 60.0,
                'samples' => [0.0, 10.0, 20.0, 30.0, 40.0, 50.0, 60.0],
                'confidence' => 0.57,
            ],
        ];

        $processingLog = MediaProcessingLog::factory()->create([
            'rms_log_path' => 'temp/rms.log',
            'song_clusters' => $songClusters,
            'status' => 'processing',
        ]);

        $this->createMockRmsLog('temp/rms.log', 600.0);

        $mockService = Mockery::mock(VideoSegmentationService::class);
        $this->mockBaselineRmsAnalysis($mockService, 600.0);

        $mockService->shouldReceive('calibratePerSongThreshold')
            ->once()
            ->andThrow(new \RuntimeException('No RMS data found for song period'));

        $mockService->shouldReceive('detectBoundariesForCluster')
            ->once()
            ->andReturn(new LivestreamSegmentData(
                startTime: 0.0,
                endTime: 73.0,
                duration: 73.0,
                classification: 'song',
                avgRms: -40.0,
                peakRms: -35.0,
                segmentOrder: 0,
                metadata: [
                    'visual_confidence' => 0.57,
                    'boundary_method' => 'visual_only',
                ],
            ));

        Log::spy();

        $job = new AnalyzeSegments($processingLog);
        $job->handle($mockService);

        Log::shouldHaveReceived('warning')
            ->with('Visual-guided calibration failed; falling back to baseline RMS threshold', Mockery::on(function (array $context) use ($processingLog): bool {
                return $context['processing_id'] === $processingLog->processing_id
                    && $context['fallback_threshold'] === -45.0
                    && str_contains($context['error'], 'No RMS data found');
            }))
            ->once();

        $processingLog->refresh();

        $this->assertNotSame('failed', $processingLog->status->value);
        $this->assertGreaterThan(0, LivestreamSegment::query()->where('media_processing_log_id', $processingLog->id)->count());
        $this->assertNotNull($processingLog->sermon_start_time);
        $this->assertNotNull($processingLog->sermon_end_time);
    }

    #[Test]
    public function it_backfills_rms_song_segments_when_visual_detection_misses_them(): void
    {
        Storage::fake('local');

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
            'song_clusters' => $songClusters,
            'status' => 'processing',
        ]);

        $this->createMockRmsLog('temp/rms.log', 500.0);

        $mockService = Mockery::mock(VideoSegmentationService::class);
        $this->mockBaselineRmsAnalysis($mockService, 500.0, [
            new LivestreamSegmentData(
                startTime: 50.0,
                endTime: 100.0,
                duration: 50.0,
                classification: 'song',
                avgRms: -35.0,
                peakRms: -33.0,
                segmentOrder: 0
            ),
            new LivestreamSegmentData(
                startTime: 300.0,
                endTime: 360.0,
                duration: 60.0,
                classification: 'song',
                avgRms: -34.0,
                peakRms: -31.0,
                segmentOrder: 1
            ),
        ]);

        $mockService->shouldReceive('calibratePerSongThreshold')->once()->andReturn([
            'threshold' => -40.0,
            'song_avg_rms' => -35.0,
            'speech_avg_rms' => -50.0,
        ]);

        $mockService->shouldReceive('detectBoundariesForCluster')->once()->andReturn(
            new LivestreamSegmentData(
                startTime: 45.0,
                endTime: 105.0,
                duration: 60.0,
                classification: 'song',
                avgRms: -35.0,
                peakRms: -33.0,
                segmentOrder: 0,
                metadata: [
                    'visual_confidence' => 0.85,
                    'calibration_method' => 'per_song_visual',
                ]
            )
        );

        $job = new AnalyzeSegments($processingLog);
        $job->handle($mockService);

        $songSegments = LivestreamSegment::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->where('classification', 'song')
            ->orderBy('start_time')
            ->get();

        $this->assertCount(2, $songSegments);
        $this->assertEquals([45.0, 300.0], $songSegments->pluck('start_time')->map(fn ($time) => (float) $time)->all());
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

    /**
     * @param  array<int, LivestreamSegmentData>  $songSegments
     */
    private function mockBaselineRmsAnalysis(Mockery\MockInterface $mockService, float $duration, array $songSegments = []): void
    {
        $mockService->shouldReceive('analyzeSegments')
            ->once()
            ->andReturn([
                'segments' => array_merge($songSegments, [
                    new LivestreamSegmentData(
                        startTime: 0.0,
                        endTime: $duration,
                        duration: $duration,
                        classification: 'speech',
                        avgRms: -50.0,
                        peakRms: -40.0,
                        isSermonCandidate: true,
                        segmentOrder: count($songSegments)
                    ),
                ]),
                'threshold_metadata' => [
                    'method' => 'adaptive',
                    'threshold' => -45.0,
                ],
            ]);
    }

    #[Test]
    public function it_assigns_segment_order_in_chronological_order(): void
    {
        Storage::fake('local');

        // Two songs with speech before, between, and after them
        $songClusters = [
            [
                'start_estimate' => 100.0,
                'end_estimate' => 200.0,
                'samples' => [100.0, 120.0, 140.0, 160.0, 180.0],
                'confidence' => 0.85,
            ],
            [
                'start_estimate' => 400.0,
                'end_estimate' => 500.0,
                'samples' => [400.0, 420.0, 440.0, 460.0, 480.0],
                'confidence' => 0.88,
            ],
        ];

        $processingLog = MediaProcessingLog::factory()->create([
            'rms_log_path' => 'temp/rms.log',
            'song_clusters' => $songClusters,
            'status' => 'processing',
        ]);

        $this->createMockRmsLog('temp/rms.log', 600.0);

        $mockService = Mockery::mock(VideoSegmentationService::class);
        $this->mockBaselineRmsAnalysis($mockService, 600.0);

        $mockService->shouldReceive('calibratePerSongThreshold')->twice()->andReturn([
            'threshold' => -40.0,
            'song_avg_rms' => -35.0,
            'speech_avg_rms' => -50.0,
        ]);

        $mockService->shouldReceive('detectBoundariesForCluster')
            ->twice()
            ->andReturn(
                new LivestreamSegmentData(
                    startTime: 95.0,
                    endTime: 205.0,
                    duration: 110.0,
                    classification: 'song',
                    avgRms: -35.0,
                    peakRms: -33.0,
                    segmentOrder: 0,
                    metadata: [
                        'visual_confidence' => 0.85,
                        'calibration_method' => 'per_song_visual',
                    ]
                ),
                new LivestreamSegmentData(
                    startTime: 395.0,
                    endTime: 505.0,
                    duration: 110.0,
                    classification: 'song',
                    avgRms: -35.0,
                    peakRms: -33.0,
                    segmentOrder: 1,
                    metadata: [
                        'visual_confidence' => 0.88,
                        'calibration_method' => 'per_song_visual',
                    ]
                )
            );

        $job = new AnalyzeSegments($processingLog);
        $job->handle($mockService);

        // Segments ordered by segment_order should also be in chronological start_time order
        $segments = LivestreamSegment::where('media_processing_log_id', $processingLog->id)
            ->orderBy('segment_order')
            ->get();

        // Verify segment_order matches chronological start_time order
        $startTimes = $segments->pluck('start_time')->map(fn ($t) => (float) $t)->all();
        $sortedStartTimes = $startTimes;
        sort($sortedStartTimes);

        $this->assertEquals($sortedStartTimes, $startTimes, 'segment_order should reflect chronological start_time order');

        // Confirm the interleaved structure: speech, song, speech, song, speech
        $classifications = $segments->pluck('classification')->all();
        $this->assertEquals([
            LivestreamSegmentClassification::Speech,
            LivestreamSegmentClassification::Song,
            LivestreamSegmentClassification::Speech,
            LivestreamSegmentClassification::Song,
            LivestreamSegmentClassification::Speech,
        ], $classifications);

        // Confirm segment_order values are 0-indexed sequential
        $segmentOrders = $segments->pluck('segment_order')->map(fn ($o) => (int) $o)->all();
        $this->assertEquals([0, 1, 2, 3, 4], $segmentOrders);
    }

    #[Test]
    public function it_skips_all_work_when_processing_is_cancelled(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->cancelled()->create();

        $mockService = $this->createMock(VideoSegmentationService::class);
        $mockService->expects($this->never())->method('analyzeSegments');
        $mockService->expects($this->never())->method('calibratePerSongThreshold');

        Log::shouldReceive('info')->once()->withArgs(fn ($msg) => str_contains($msg, 'job skipped: processing cancelled'));

        $job = new AnalyzeSegments($log);
        $job->handle($mockService);
    }

    #[Test]
    public function it_replaces_partial_segment_rows_when_rerun(): void
    {
        Storage::fake('local');

        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create([
            'rms_log_path' => 'temp/rms.log',
            'sermon_start_time' => 10.0,
            'sermon_end_time' => 20.0,
            'threshold_method' => 'stale',
        ]);

        LivestreamSegment::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'segment_index' => 0,
            'segment_order' => 0,
            'start_time' => 0.0,
            'end_time' => 30.0,
            'duration' => 30.0,
            'classification' => 'song',
        ]);

        $this->createMockRmsLog('temp/rms.log', 900.0);

        $mockService = Mockery::mock(VideoSegmentationService::class);
        $mockService->shouldReceive('analyzeSegments')
            ->once()
            ->andReturn([
                'segments' => [
                    new LivestreamSegmentData(
                        startTime: 0.0,
                        endTime: 120.0,
                        duration: 120.0,
                        classification: 'song',
                        avgRms: -35.0,
                        peakRms: -30.0,
                        segmentOrder: 0
                    ),
                    new LivestreamSegmentData(
                        startTime: 120.0,
                        endTime: 780.0,
                        duration: 660.0,
                        classification: 'speech',
                        avgRms: -50.0,
                        peakRms: -40.0,
                        isSermonCandidate: true,
                        segmentOrder: 1
                    ),
                ],
                'threshold_metadata' => [
                    'method' => 'adaptive',
                    'threshold' => -45.0,
                ],
            ]);

        $job = new AnalyzeSegments($processingLog);
        $job->handle($mockService);

        $processingLog->refresh();

        $this->assertSame(2, LivestreamSegment::query()->where('media_processing_log_id', $processingLog->id)->count());
        $this->assertSame(120.0, (float) LivestreamSegment::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->where('segment_index', 1)
            ->value('start_time'));
        $this->assertSame('adaptive', $processingLog->threshold_method);
        $this->assertSame(120.0, $processingLog->sermon_start_time);
        $this->assertSame(780.0, $processingLog->sermon_end_time);
    }
}
